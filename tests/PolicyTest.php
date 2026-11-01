<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tests;

use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Voter\CoreVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;

final class PolicyTest extends TestCase
{
    /** @dataProvider grants */
    public function testOnlyNamespacedPoliciesGrantAccess(string $function, bool $namespacedGrant): void
    {
        $permissions = $this->createMock(PermissionResolver::class);
        $permissions->expects(self::once())->method('hasAccess')
            ->with('wizhippo_ibexa_content_scheduler', $function)
            ->willReturnCallback(static fn (string $module): bool => $module === 'content_scheduler' || $namespacedGrant);
        $policy = new ContentSchedulerPolicy(new AuthorizationChecker(new TokenStorage(), new AccessDecisionManager([new CoreVoter($permissions)])));

        self::assertSame($namespacedGrant, $policy->isGranted($function));
    }

    public static function grants(): iterable
    {
        foreach (['view', 'schedule', 'manage'] as $function) {
            yield $function.' with namespaced grant' => [$function, true];
            yield $function.' with legacy grant only' => [$function, false];
        }
    }
}
