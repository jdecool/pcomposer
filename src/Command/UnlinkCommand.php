<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UnlinkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('pcomposer:unlink')
            ->setDescription('Replaces the hard links of a package with private writable copies')
            ->addArgument('package', InputArgument::REQUIRED, 'Package name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }
}
