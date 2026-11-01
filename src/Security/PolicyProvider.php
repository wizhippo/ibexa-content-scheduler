<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Security;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\ConfigBuilderInterface;
use Ibexa\Bundle\Core\DependencyInjection\Security\PolicyProvider\PolicyProviderInterface;

final class PolicyProvider implements PolicyProviderInterface
{
    public function addPolicies(ConfigBuilderInterface $configBuilder): void
    {
        $configBuilder->addConfig([
            ContentSchedulerPolicy::MODULE => [
                ContentSchedulerPolicy::FUNCTION_VIEW => null,
                ContentSchedulerPolicy::FUNCTION_SCHEDULE => null,
                ContentSchedulerPolicy::FUNCTION_MANAGE => null,
            ],
        ]);
    }
}
