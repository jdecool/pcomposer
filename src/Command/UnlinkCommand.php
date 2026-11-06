<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Command;

use Composer\Command\BaseCommand;
use JDecool\Composer\Plugin\PComposer\Plugin;
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
        $name = $input->getArgument('package');
        \assert(\is_string($name));
        $composer = $this->requireComposer();
        $manifest = Plugin::manifest($composer);

        $entry = $manifest->read()[$name] ?? null;
        $package = $composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
        if (null === $entry || null === $package) {
            $output->writeln("<error>$name is not linked from the store.</error>");

            return 1;
        }
        if ('hardlink' !== $entry['strategy']) {
            $output->writeln("$name is installed with the {$entry['strategy']} strategy, nothing to unlink.");

            return 0;
        }

        $dir = (string) $composer->getInstallationManager()->getInstallPath($package);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !$file->isLink()) {
                self::privatise($file->getPathname(), $file->isExecutable() ? 0755 : 0644);
            }
        }
        $manifest->setStrategy($name, 'copy');
        $output->writeln("$name is now a private copy.");

        return 0;
    }

    /** Copy to a sibling temp file, then rename over the link: the store file keeps its own inode and mode. */
    private static function privatise(string $path, int $mode): void
    {
        $tmp = \dirname($path).'/.pcomposer-unlink-'.bin2hex(random_bytes(4));
        if (!copy($path, $tmp) || !chmod($tmp, $mode) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot unlink $path");
        }
    }
}
