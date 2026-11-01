<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wizhippo\IbexaContentScheduler\Service\ScheduleRunner;

#[AsCommand(name: 'wizhippo:content-scheduler:run', description: 'Execute a bounded batch of overdue content schedules.')]
final class RunCommand extends Command
{
    public function __construct(private readonly ScheduleRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum schedules per invocation (1–500).', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->runner->run((int) $input->getOption('limit'));
        $output->writeln(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] || $counts['conflict'] ? self::FAILURE : self::SUCCESS;
    }
}
