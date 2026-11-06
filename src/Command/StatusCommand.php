<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use JDecool\Composer\Plugin\PComposer\Manifest;
use JDecool\Composer\Plugin\PComposer\Store;
use Symfony\Component\Console\Helper\Table;
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
        $config = $this->requireComposer()->getConfig();
        $vendorDir = $config->get('vendor-dir');
        \assert(\is_string($vendorDir));
        $store = Store::fromConfig($config);

        $entries = Manifest::inVendorDir($vendorDir)->read();
        if ([] === $entries) {
            $output->writeln('No package linked from the store (no vendor/composer/pcomposer.json).');
        } else {
            (new Table($output))
                ->setHeaders(['Package', 'Version', 'Strategy'])
                ->setRows(array_map(static fn (array $e): array => [$e['name'], $e['version'], $e['strategy']], array_values($entries)))
                ->render();
        }
        $output->writeln(\sprintf('Store: %s (%d bytes)', $store->path(), $store->size()));

        return 0;
    }
}
