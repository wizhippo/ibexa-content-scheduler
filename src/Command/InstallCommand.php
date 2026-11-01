<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wizhippo\IbexaContentScheduler\Persistence\Schema;

#[AsCommand(name: 'wizhippo:content-scheduler:install', description: 'Preview or create missing scheduler tables on the Ibexa repository connection.')]
final class InstallCommand extends Command
{
    public function __construct(private readonly Schema $schema)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Execute the displayed schema statements.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statements = $this->schema->install((bool) $input->getOption('execute'));
        foreach ($statements as $sql) {
            $output->writeln($sql.';', OutputInterface::OUTPUT_RAW);
        }
        $output->writeln($statements === [] ? 'Scheduler tables already exist.' : ($input->getOption('execute') ? 'Scheduler tables created.' : 'Preview only. Add --execute to create these tables.'));

        return self::SUCCESS;
    }
}
