<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tests;

use Ibexa\AdminUi\Menu\ContentEditRightSidebarBuilder;
use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wizhippo\IbexaContentScheduler\Menu\ScheduleMenuSubscriber;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;

final class ScheduleMenuSubscriberTest extends TestCase
{
    /** @dataProvider accessCases */
    public function testPublishLaterRequiresFeatureAndNativePublicationAccess(bool $feature, bool $nativeDisabled, bool $visible): void
    {
        $security = $this->createMock(AuthorizationCheckerInterface::class);
        $security->method('isGranted')->willReturnCallback(static function (mixed $attribute) use ($feature): bool {
            if ($attribute !== 'IS_AUTHENTICATED_REMEMBERED') {
                self::assertInstanceOf(Attribute::class, $attribute);
                self::assertSame('wizhippo_ibexa_content_scheduler', $attribute->module);
                self::assertContains($attribute->function, ['view', 'schedule']);
            }

            return $feature;
        });
        $factory = new MenuFactory();
        $menu = $factory->createItem('root');
        $publish = $menu->addChild(ContentEditRightSidebarBuilder::ITEM__PUBLISH);
        if ($nativeDisabled) {
            $publish->setAttribute('disabled', 'disabled');
        }
        (new ScheduleMenuSubscriber($security, new ContentSchedulerPolicy($security)))->editor(new ConfigureMenuEvent($factory, $menu));
        self::assertSame($visible, $menu->getChild('wizhippo_publish_later') !== null);
    }

    public static function accessCases(): array
    {
        return [[true, false, true], [true, true, false], [false, false, false]];
    }
}
