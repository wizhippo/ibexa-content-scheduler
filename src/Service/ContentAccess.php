<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Service;

use Ibexa\Contracts\Core\Limitation\Target;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;

final class ContentAccess
{
    public function __construct(private readonly Repository $repository, private readonly ContentSchedulerPolicy $policy)
    {
    }

    public function read(int $contentId, int $version = 0): Content
    {
        if (!$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW)) {
            throw new AccessDeniedException('Content scheduling access is required.');
        }
        $content = $this->repository->getContentService()->loadContent($contentId, null, $version ?: null, false);
        $permission = $this->repository->getPermissionResolver();
        if (!$permission->canUser('content', 'read', $content) || !$permission->canUser('content', 'versionread', $content)) {
            throw new AccessDeniedException('This content version is unavailable.');
        }

        return $content;
    }

    public function authorize(Content $content, string $action, array $languages): void
    {
        $permission = $this->repository->getPermissionResolver();
        if (!$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)) {
            throw new AccessDeniedException('Scheduling permission is required.');
        }
        $info = $content->getContentInfo();
        $targets = $action === 'publish'
            ? [(new Target\Builder\VersionBuilder())->publishTranslations($languages)->build()]
            : ($info->isDraft() ? [] : [new Target\DestinationLocation($info->getMainLocationId(), $info)]);
        $editTargets = $action === 'publish' ? [(new Target\Builder\VersionBuilder())->translateToAnyLanguageOf($languages)->build()] : [];
        if (!$permission->canUser('content', $action === 'publish' ? 'publish' : 'hide', $content, $targets)
            || ($action === 'publish' && !$permission->canUser('content', 'edit', $content, $editTargets))) {
            throw new AccessDeniedException('You cannot schedule this action on this content.');
        }
    }

    public function manage(array $row): void
    {
        if ((int) $row['requested_by'] !== $this->actor() && !$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_MANAGE)) {
            throw new AccessDeniedException('You can only manage your own schedules.');
        }
        $this->read((int) $row['content_id'], (int) $row['version_no']);
    }

    public function actor(): int
    {
        return $this->repository->getPermissionResolver()->getCurrentUserReference()->getUserId();
    }
}
