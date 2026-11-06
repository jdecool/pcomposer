<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use JDecool\Composer\Plugin\PComposer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('pcomposer:prune')
            ->setDescription('Removes index entries not used within the window, then unreferenced store files')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Window as <N>d, <N>h or <N>m', '30d')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be removed without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = $input->getOption('older-than');
        \assert(\is_string($window));
        if (1 !== preg_match('/^(\d+)([dhm])$/', $window, $m)) {
            $this->getIO()->writeError("<error>Invalid --older-than \"$window\": expected <N>d, <N>h or <N>m</error>");

            return 1;
        }
        $seconds = (int) $m[1] * ['d' => 86400, 'h' => 3600, 'm' => 60][$m[2]];
        $dryRun = (bool) $input->getOption('dry-run');

        ['entries' => $entries, 'files' => $files] = Plugin::store($this->requireComposer())->prune(time() - $seconds, $dryRun);
        $verb = $dryRun ? 'Would remove' : 'Removed';
        foreach ([...$entries, ...$files] as $path) {
            $this->getIO()->write("$verb $path", true, $dryRun ? IOInterface::NORMAL : IOInterface::VERBOSE);
        }
        $this->getIO()->write(\sprintf('%s %d index entries and %d store files', $verb, \count($entries), \count($files)));

        return 0;
    }
}
