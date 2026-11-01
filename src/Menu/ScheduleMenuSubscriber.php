<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Menu;

use Ibexa\AdminUi\Menu\ContentCreateRightSidebarBuilder;
use Ibexa\AdminUi\Menu\ContentEditRightSidebarBuilder;
use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;

final class ScheduleMenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security, private readonly ContentSchedulerPolicy $policy)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMenuEvent::MAIN_MENU => 'main', ConfigureMenuEvent::CONTENT_EDIT_SIDEBAR_RIGHT => 'editor', ConfigureMenuEvent::CONTENT_CREATE_SIDEBAR_RIGHT => 'editor', ConfigureMenuEvent::CONTENT_SIDEBAR_RIGHT => 'content'];
    }

    private function allowed(): bool
    {
        return $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') && $this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW);
    }

    public function main(ConfigureMenuEvent $event): void
    {
        if ($this->allowed()) {
            $event->getMenu()->getChild(MainMenuBuilder::ITEM_CONTENT)?->addChild('wizhippo_schedules', ['route' => 'wizhippo_schedule_list', 'label' => 'Scheduled content', 'extras' => ['translation_domain' => false]]);
        }
    }

    public function editor(ConfigureMenuEvent $event): void
    {
        if (!$this->allowed() || !$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)) {
            return;
        }
        $publish = $event->getMenu()->getChild(ContentEditRightSidebarBuilder::ITEM__PUBLISH)
            ?? $event->getMenu()->getChild(ContentCreateRightSidebarBuilder::ITEM__PUBLISH);
        if ($publish === null || $publish->getAttribute('disabled')) {
            return;
        }
        $event->getMenu()->addChild('wizhippo_publish_later', [
            'label' => 'Publish later',
            'attributes' => ['class' => 'ibexa-btn--trigger', 'data-click' => '#ezplatform_content_forms_content_edit_publishLater'],
            'extras' => ['orderNumber' => 15, 'translation_domain' => false, 'icon' => 'calendar'],
        ]);
    }

    public function content(ConfigureMenuEvent $event): void
    {
        $content = $event->getOptions()['content'] ?? null;
        if (!$this->allowed() || !$content instanceof Content) {
            return;
        }
        $event->getMenu()->addChild('wizhippo_content_schedules', ['label' => 'Scheduled actions', 'route' => 'wizhippo_schedule_list', 'routeParameters' => ['contentId' => $content->getId(), 'mine' => 0], 'extras' => ['orderNumber' => 85, 'translation_domain' => false, 'icon' => 'calendar']]);
    }
}
