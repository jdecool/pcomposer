<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use JDecool\Composer\Plugin\PComposer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class VerifyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('pcomposer:verify')->setDescription('Rehashes every store file and reports mismatches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ['checked' => $checked, 'corrupted' => $corrupted] = Plugin::store($this->requireComposer())->verify();
        foreach ($corrupted as $path) {
            $this->getIO()->writeError("<error>Corrupted: $path</error>");
        }
        $this->getIO()->write(\sprintf('%d files checked, %d corrupted', $checked, \count($corrupted)));

        return [] === $corrupted ? 0 : 1;
    }
}
