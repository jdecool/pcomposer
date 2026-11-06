<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('pcomposer:status')->setDescription('Lists the packages linked from the pcomposer store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }
}
