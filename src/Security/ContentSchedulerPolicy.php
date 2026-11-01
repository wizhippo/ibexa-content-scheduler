<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Security;

use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Bundle-scoped Ibexa policies. */
final readonly class ContentSchedulerPolicy
{
    public const string MODULE = 'wizhippo_ibexa_content_scheduler';

    public const string FUNCTION_VIEW = 'view';
    public const string FUNCTION_SCHEDULE = 'schedule';
    public const string FUNCTION_MANAGE = 'manage';

    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function isGranted(string $function): bool
    {
        return $this->authorizationChecker->isGranted(new Attribute(self::MODULE, $function));
    }
}
