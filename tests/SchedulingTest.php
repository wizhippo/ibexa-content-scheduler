<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tests;

use Doctrine\DBAL\DriverManager;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\FieldTypeService;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\UserService;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\Language;
use Ibexa\Core\FieldType\TextLine\Type;
use Ibexa\Core\FieldType\TextLine\Value;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Voter\CoreVoter;
use Ibexa\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinitionCollection;
use Ibexa\Core\Repository\Values\Content\Content;
use Ibexa\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Core\Repository\Values\User\User;
use Ibexa\Core\Repository\Values\User\UserReference;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;
use Wizhippo\IbexaContentScheduler\Persistence\Schema;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;
use Wizhippo\IbexaContentScheduler\Service\ContentAccess;
use Wizhippo\IbexaContentScheduler\Service\Fingerprint;
use Wizhippo\IbexaContentScheduler\Service\ScheduleRunner;
use Wizhippo\IbexaContentScheduler\Service\ScheduleService;

final class SchedulingTest extends TestCase
{
    private ScheduleStore $store;

    private ScheduleService $service;

    private ScheduleRunner $runner;

    private MockClock $clock;

    private ContentService&MockObject $contentService;

    private ContentInfo $info;

    private Content $content;

    private Repository $repository;

    private ContentAccess $access;

    private ContentSchedulerPolicy $policy;

    private mixed $current;

    private bool $allowed = true;

    private bool $enabled = true;

    private array $validationErrors = [];

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new Schema($connection))->install(true);
        $this->store = new ScheduleStore($connection);
        $this->clock = new MockClock('2026-10-31T12:00:00Z');
        $this->current = new UserReference(42);
        $this->info = new ContentInfo(['id' => 12, 'status' => ContentInfo::STATUS_PUBLISHED, 'currentVersionNo' => 1, 'isHidden' => false, 'name' => 'Article', 'mainLanguageCode' => 'eng-GB', 'mainLocationId' => 2]);
        $this->content = $this->draft('Original');
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('loadContent')->willReturnCallback(fn () => $this->content);
        $this->contentService->method('loadContentInfo')->willReturnCallback(fn () => $this->info);
        $this->contentService->method('loadVersionInfo')->willReturnCallback(fn () => $this->content->getVersionInfo());
        $this->contentService->method('validate')->willReturnCallback(fn () => $this->validationErrors);
        $permissions = $this->createMock(PermissionResolver::class);
        $permissions->method('getCurrentUserReference')->willReturnCallback(fn () => $this->current);
        $permissions->method('setCurrentUserReference')->willReturnCallback(function ($user): void { $this->current = $user; });
        $permissions->method('hasAccess')->willReturnCallback(function (string $module): bool {
            self::assertSame('wizhippo_ibexa_content_scheduler', $module);

            return $this->allowed;
        });
        $permissions->method('canUser')->willReturnCallback(fn () => $this->allowed);
        $users = $this->createMock(UserService::class);
        $users->method('loadUser')->willReturnCallback(fn () => new User(['enabled' => $this->enabled, 'content' => new Content(['versionInfo' => new VersionInfo(['contentInfo' => new ContentInfo(['id' => 42])])])]));
        $repository = $this->createMock(Repository::class);
        $this->repository = $repository;
        $repository->method('getContentService')->willReturn($this->contentService);
        $repository->method('getPermissionResolver')->willReturn($permissions);
        $repository->method('getUserService')->willReturn($users);
        $types = $this->createMock(FieldTypeService::class);
        $type = $this->createMock(\Ibexa\Contracts\Core\Repository\FieldType::class);
        $type->method('toHash')->willReturnCallback(fn ($value) => (new Type())->toHash($value));
        $types->method('getFieldType')->willReturn($type);
        $this->policy = new ContentSchedulerPolicy(new AuthorizationChecker(new TokenStorage(), new AccessDecisionManager([new CoreVoter($permissions)])));
        $access = new ContentAccess($repository, $this->policy);
        $this->access = $access;
        $this->service = new ScheduleService($this->store, $repository, $access, new Fingerprint($types), $this->clock);
        $this->runner = new ScheduleRunner($this->store, $repository, $this->service, $this->clock, new NullLogger());
    }

    public function testExactVersionAndLanguagesExecuteOnceAndRestoreIdentity(): void
    {
        $previous = $this->current;
        $id = $this->schedule();
        $this->contentService->expects(self::once())->method('publishVersion')->with($this->content->getVersionInfo(), ['eng-GB'])->willReturn($this->content);
        self::assertSame(0, $this->runner->run()['completed']);
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['completed']);
        self::assertSame(0, $this->runner->run()['completed']);
        self::assertSame($previous, $this->current);
        self::assertSame('completed', $this->store->get($id)['status']);
        self::assertCount(2, $this->store->history($id));
    }

    public function testCancellationPreservesDraftAndPreventsExecution(): void
    {
        $id = $this->schedule();
        $this->service->cancel($id, 1);
        $this->clock->sleep(61);
        $this->contentService->expects(self::never())->method('publishVersion');
        self::assertSame(0, $this->runner->run()['completed']);
        self::assertSame('cancelled', $this->store->get($id)['status']);
        self::assertTrue($this->content->getVersionInfo()->isDraft());
    }

    public function testStaleRevisionCannotCancelRescheduledJob(): void
    {
        $id = $this->schedule();
        $this->service->reschedule($id, 1, $this->clock->now()->modify('+5 minutes'), 'UTC');
        $this->expectException(ScheduleException::class);
        $this->service->cancel($id, 1);
    }

    public function testOnlyOnePendingPublicationPerContent(): void
    {
        $this->schedule();
        $this->expectException(ScheduleException::class);
        $this->schedule();
    }

    public function testChangedDraftIsNotPublishedAndCanBeExplicitlyReconfirmed(): void
    {
        $id = $this->schedule();
        $this->content = $this->draft('Changed within the same timestamp');
        $this->clock->sleep(61);
        $this->contentService->expects(self::once())->method('publishVersion')->willReturn($this->content);
        self::assertSame(1, $this->runner->run()['conflict']);
        $this->service->reschedule($id, 2, $this->clock->now()->modify('+1 minute'), 'UTC');
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['completed']);
    }

    public function testNewerPublicationIsNeverOverwritten(): void
    {
        $this->schedule();
        $this->info = new ContentInfo(['id' => 12, 'status' => ContentInfo::STATUS_PUBLISHED, 'currentVersionNo' => 3]);
        $this->clock->sleep(61);
        $this->contentService->expects(self::never())->method('publishVersion');
        self::assertSame(1, $this->runner->run()['conflict']);
    }

    public function testRevokedPermissionPreventsExecutionAndRestoresIdentity(): void
    {
        $previous = $this->current;
        $this->schedule();
        $this->allowed = false;
        $this->clock->sleep(61);
        $this->contentService->expects(self::never())->method('publishVersion');
        self::assertSame(1, $this->runner->run()['failed']);
        self::assertSame($previous, $this->current);
    }

    public function testDisabledRequesterCannotExecute(): void
    {
        $this->schedule();
        $this->enabled = false;
        $this->clock->sleep(61);
        $this->contentService->expects(self::never())->method('publishVersion');
        self::assertSame(1, $this->runner->run()['conflict']);
    }

    public function testAnotherEditorCannotCancelWithoutManagePolicy(): void
    {
        $id = $this->schedule();
        $this->current = new UserReference(43);
        $this->allowed = false;
        $this->expectException(AccessDeniedException::class);
        $this->service->cancel($id, 1);
    }

    public function testVisibilityChangesUseContentWideApi(): void
    {
        $id = $this->service->create(12, 'hide', 0, [], $this->clock->now()->modify('+1 minute'), 'UTC');
        $this->contentService->expects(self::once())->method('hideContent')->with($this->info);
        $this->contentService->expects(self::never())->method('publishVersion');
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['completed']);
        self::assertSame('completed', $this->store->get($id)['status']);
    }

    public function testFailedTransactionRollsBackContentAndKeepsAuditOutcome(): void
    {
        $id = $this->schedule();
        $this->contentService->method('publishVersion')->willReturnCallback(function (): never {
            $this->store->connection->executeStatement('UPDATE wizhippo_schedule_lock SET revision = 999 WHERE content_id = 12');
            throw new \RuntimeException('Private field content must not be persisted in failure messages');
        });
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['failed']);
        self::assertNotSame(999, (int) $this->store->connection->fetchOne('SELECT revision FROM wizhippo_schedule_lock WHERE content_id = 12'));
        self::assertStringNotContainsString('Private field', $this->store->get($id)['error']);
    }

    public function testInstallerIsIdempotentAndDoesNotTouchOtherTables(): void
    {
        $this->store->connection->executeStatement('CREATE TABLE accounting_fixture (id INTEGER)');
        self::assertSame([], (new Schema($this->store->connection))->install(true));
        self::assertContains('accounting_fixture', $this->store->connection->createSchemaManager()->listTableNames());
    }

    public function testInvalidDraftCannotBeScheduled(): void
    {
        $this->validationErrors = ['title' => ['eng-GB' => ['Required']]];
        $this->expectException(ScheduleException::class);
        $this->schedule();
    }

    public function testInterruptedIndexInstallationCanBeRecovered(): void
    {
        $this->store->connection->executeStatement('DROP INDEX wizhippo_schedule_due');
        $installer = new Schema($this->store->connection);
        self::assertCount(1, $installer->install(false));
        self::assertArrayNotHasKey('wizhippo_schedule_due', $this->store->connection->createSchemaManager()->listTableIndexes('wizhippo_schedule'));
        self::assertCount(1, $installer->install(true));
        self::assertSame([], $installer->install(true));
    }

    public function testRevealUsesNativeApiAndPreservesPublication(): void
    {
        $this->info = new ContentInfo(['id' => 12, 'status' => ContentInfo::STATUS_PUBLISHED, 'currentVersionNo' => 1, 'isHidden' => true, 'mainLocationId' => 2]);
        $this->service->create(12, 'reveal', 0, [], $this->clock->now()->modify('+1 minute'), 'UTC');
        $this->contentService->expects(self::once())->method('revealContent')->with($this->info);
        $this->contentService->expects(self::never())->method('publishVersion');
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['completed']);
    }

    public function testUnavailableTranslationCannotBeScheduled(): void
    {
        $this->expectException(ScheduleException::class);
        $this->service->create(12, 'publish', 2, ['ger-DE'], $this->clock->now()->modify('+1 minute'), 'UTC');
    }

    public function testTransientDatabaseErrorIsRetriedWithBackoff(): void
    {
        $id = $this->schedule();
        $calls = 0;
        $this->contentService->method('publishVersion')->willReturnCallback(function () use (&$calls): Content {
            if (++$calls === 1) {
                throw new class extends \RuntimeException implements \Doctrine\DBAL\Exception\RetryableException {};
            }

            return $this->content;
        });
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['retry']);
        self::assertSame(0, $this->runner->run()['completed']);
        $this->clock->sleep(61);
        self::assertSame(1, $this->runner->run()['completed']);
        self::assertSame(2, (int) $this->store->get($id)['attempts']);
    }

    public function testDatabaseLockAndMutationUseRepositoryTransactionLifecycle(): void
    {
        $repository = $this->createMock(Repository::class);
        $connection = $this->store->connection;
        $repository->expects(self::once())->method('beginTransaction')->willReturnCallback(fn () => $connection->beginTransaction());
        $repository->expects(self::once())->method('commit')->willReturnCallback(fn () => $connection->commit());
        $repository->expects(self::never())->method('rollback');
        $store = new ScheduleStore($connection, $repository);
        $store->ensureLock(99);
        self::assertSame('result', $store->locked(99, function () use ($connection): string {
            self::assertTrue($connection->isTransactionActive());

            return 'result';
        }));
        self::assertFalse($connection->isTransactionActive());
    }

    /** @dataProvider controllerActions */
    public function testMissingViewPolicyRejectsEveryControllerAction(string $action, array $arguments): void
    {
        $this->allowed = false;
        $controller = new \Wizhippo\IbexaContentScheduler\Controller\ScheduleController($this->store, $this->access, $this->service, $this->clock, $this->policy);
        $arguments[] = \Symfony\Component\HttpFoundation\Request::create('/');

        $this->expectException(AccessDeniedException::class);
        $controller->{$action}(...$arguments);
    }

    public static function controllerActions(): iterable
    {
        yield 'schedule list' => ['index', []];
        yield 'create schedule' => ['create', [12, 'publish', 2]];
        yield 'schedule detail' => ['detail', ['unavailable']];
    }

    public function testInvalidCsrfTokenCannotCancelSchedule(): void
    {
        $id = $this->schedule();
        $controller = new \Wizhippo\IbexaContentScheduler\Controller\ScheduleController($this->store, $this->access, $this->service, $this->clock, $this->policy);
        $tokens = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $tokens->method('isTokenValid')->willReturn(false);
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->set('security.csrf.token_manager', $tokens);
        $controller->setContainer($container);
        try {
            $controller->detail($id, \Symfony\Component\HttpFoundation\Request::create('/', 'POST', ['_token' => 'invalid', 'operation' => 'cancel', 'revision' => 1]));
            self::fail('An invalid token must be rejected.');
        } catch (AccessDeniedException) {
            self::assertSame('pending', $this->store->get($id)['status']);
        }
    }

    public function testReschedulingPreservesFieldsAndOffersBothRepeatedTimes(): void
    {
        $id = $this->schedule();
        $controller = new \Wizhippo\IbexaContentScheduler\Controller\ScheduleController($this->store, $this->access, $this->service, $this->clock, $this->policy);
        $tokens = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $tokens->method('isTokenValid')->willReturn(true);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->expects(self::once())->method('render')->with('@WizhippoIbexaContentScheduler/detail.html.twig', self::callback(static function (array $parameters): bool {
            self::assertSame('2026-11-01', $parameters['date']);
            self::assertSame('01:30', $parameters['localTime']);
            self::assertSame('America/Toronto', $parameters['timezone']);
            self::assertCount(2, $parameters['timeOccurrences']);
            self::assertStringContainsString('occurs twice', $parameters['error']);

            return true;
        }))->willReturn('<form></form>');
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->set('security.csrf.token_manager', $tokens);
        $container->set('twig', $twig);
        $controller->setContainer($container);
        $response = $controller->detail($id, \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [
            '_token' => 'valid', 'operation' => 'reschedule', 'revision' => 1,
            'date' => '2026-11-01', 'local_time' => '01:30', 'timezone' => 'America/Toronto',
        ]));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, (int) $this->store->get($id)['revision']);
    }

    public function testExistingRepeatedTimeKeepsItsChosenOccurrence(): void
    {
        $id = $this->schedule();
        $later = \Wizhippo\IbexaContentScheduler\Domain\ExecutionTime::parseLocal('2026-11-01', '01:30', 'America/Toronto', 'later', $this->clock->now());
        $this->service->reschedule($id, 1, $later, 'America/Toronto');
        $controller = new \Wizhippo\IbexaContentScheduler\Controller\ScheduleController($this->store, $this->access, $this->service, $this->clock, $this->policy);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->expects(self::once())->method('render')->with('@WizhippoIbexaContentScheduler/detail.html.twig', self::callback(static function (array $parameters): bool {
            self::assertSame('2026-11-01', $parameters['date']);
            self::assertSame('01:30', $parameters['localTime']);
            self::assertSame('later', $parameters['occurrence']);

            return true;
        }))->willReturn('<form></form>');
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->set('twig', $twig);
        $controller->setContainer($container);
        self::assertSame(200, $controller->detail($id, \Symfony\Component\HttpFoundation\Request::create('/'))->getStatusCode());
    }

    public function testPublishLaterSavesUnsavedFieldsWithoutPublishing(): void
    {
        $field = new \Ibexa\Contracts\ContentForms\Data\Content\FieldData([
            'fieldDefinition' => new FieldDefinition(['identifier' => 'title', 'isTranslatable' => true]),
            'value' => new Value('Unsaved title'),
        ]);
        $data = new \Ibexa\ContentForms\Data\Content\ContentUpdateData(['contentDraft' => $this->content, 'fieldsData' => ['title' => $field]]);
        $config = $this->createMock(\Symfony\Component\Form\FormConfigInterface::class);
        $config->method('getOption')->with('languageCode')->willReturn('eng-GB');
        $form = $this->createMock(\Symfony\Component\Form\FormInterface::class);
        $form->method('getConfig')->willReturn($config);
        $urls = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $urls->expects(self::once())->method('generate')->with('wizhippo_schedule_create', ['contentId' => 12, 'action' => 'publish', 'version' => 2, 'language' => 'eng-GB'])->willReturn('/choose-time');
        $this->contentService->expects(self::never())->method('publishVersion');
        $this->contentService->expects(self::once())->method('updateContent')->with($this->content->getVersionInfo(), self::callback(static function ($submitted): bool {
            return (string) $submitted->fields[0]->value === 'Unsaved title';
        }))->willReturn($this->content);
        $event = new \Ibexa\ContentForms\Event\FormActionEvent($form, $data, 'publishLater');
        (new \Wizhippo\IbexaContentScheduler\Form\PublishLaterProcessor($this->repository, $this->access, $urls, $this->policy))->saveAndChooseTime($event);
        self::assertSame('/choose-time', $event->getResponse()->headers->get('Location'));
    }

    private function schedule(): string
    {
        return $this->service->create(12, 'publish', 2, ['eng-GB'], $this->clock->now()->modify('+1 minute'), 'UTC');
    }

    private function draft(string $text): Content
    {
        return new Content([
            'versionInfo' => new VersionInfo(['versionNo' => 2, 'status' => VersionInfo::STATUS_DRAFT, 'contentInfo' => $this->info, 'languageCodes' => ['eng-GB', 'fre-CA'], 'modificationDate' => new \DateTimeImmutable('2026-10-31T12:00:00Z'), 'initialLanguage' => new Language(['languageCode' => 'eng-GB'])]),
            'contentType' => new ContentType(['fieldDefinitions' => new FieldDefinitionCollection([new FieldDefinition(['identifier' => 'title', 'fieldTypeIdentifier' => 'ezstring', 'isRequired' => true, 'isTranslatable' => true, 'validatorConfiguration' => [], 'fieldSettings' => []])])]),
            'internalFields' => [new Field(['fieldDefIdentifier' => 'title', 'fieldTypeIdentifier' => 'ezstring', 'languageCode' => 'eng-GB', 'value' => new Value($text)])],
        ]);
    }
}
