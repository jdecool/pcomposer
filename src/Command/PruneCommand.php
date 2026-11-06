<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('pcomposer:prune')->setDescription('Removes index entries not used within the window, then unreferenced store files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }
}
