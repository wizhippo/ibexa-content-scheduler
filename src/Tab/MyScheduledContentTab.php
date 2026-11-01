<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tab;

use Ibexa\Contracts\AdminUi\Tab\AbstractTab;
use Ibexa\Contracts\AdminUi\Tab\ConditionalTabInterface;
use Ibexa\Contracts\AdminUi\Tab\OrderedTabInterface;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;
use Wizhippo\IbexaContentScheduler\Service\ContentAccess;

final class MyScheduledContentTab extends AbstractTab implements ConditionalTabInterface, OrderedTabInterface
{
    public function __construct(Environment $twig, TranslatorInterface $translator, private readonly ContentAccess $access, private readonly ScheduleStore $store, private readonly ContentSchedulerPolicy $policy)
    {
        parent::__construct($twig, $translator);
    }

    public function getIdentifier(): string
    {
        return 'wizhippo-my-scheduled-content';
    }

    public function getName(): string
    {
        return 'Scheduled content';
    }

    public function getOrder(): int
    {
        return 150;
    }

    public function evaluate(array $parameters): bool
    {
        return $this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW);
    }

    public function renderView(array $parameters): string
    {
        $rows = [];
        foreach ($this->store->page(0, $this->access->actor(), null, 'pending') as $row) {
            try {
                $content = $this->access->read((int) $row['content_id'], (int) $row['version_no']);
                $rows[] = $row + ['content_name' => $content->getName()];
            } catch (NotFoundException|UnauthorizedException|AccessDeniedException) {
            }
        }

        return $this->twig->render('@WizhippoIbexaContentScheduler/dashboard.html.twig', ['rows' => $rows]);
    }
}
