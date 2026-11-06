<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;

/**
 * `vendor/composer/pcomposer.json`: the packages the plugin materialised, keyed by name.
 *
 * Shape: {"<name>": {"name", "version", "reference", "strategy"}}, sorted by name.
 * Packages installed by Composer's normal path never appear.
 */
final class Manifest
{
    /** @var array<string, array{name: string, version: string, reference: string, strategy: string}> */
    private array $recorded = [];

    public function __construct(private readonly string $path)
    {
    }

    public static function inVendorDir(string $vendorDir): self
    {
        return new self($vendorDir.'/composer/pcomposer.json');
    }

    /** Remembers a package materialised during this run, with its effective strategy. */
    public function record(PackageInterface $package, string $strategy): void
    {
        $this->recorded[$package->getName()] = [
            'name' => $package->getName(),
            'version' => $package->getPrettyVersion(),
            'reference' => $package->getDistReference() ?? $package->getDistSha1Checksum() ?? '',
            'strategy' => $strategy,
        ];
    }

    /** @return array<string, array{name: string, version: string, reference: string, strategy: string}> */
    public function read(): array
    {
        $json = @file_get_contents($this->path);
        if (false === $json) {
            return [];
        }

        /** @var array<string, array{name: string, version: string, reference: string, strategy: string}> $entries */
        $entries = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $entries;
    }

    /** Merges this run's records over the file and drops packages no longer installed at the recorded version. */
    public function write(RepositoryInterface $installed): void
    {
        $versions = [];
        foreach ($installed->getPackages() as $package) {
            $versions[$package->getName()] = $package->getPrettyVersion();
        }
        $entries = array_filter(
            $this->recorded + $this->read(),
            static fn (array $entry, string $name): bool => ($versions[$name] ?? null) === $entry['version'],
            \ARRAY_FILTER_USE_BOTH,
        );
        ksort($entries);

        if (!is_dir(\dirname($this->path))) {
            mkdir(\dirname($this->path), 0777, true);
        }
        file_put_contents($this->path, json_encode((object) $entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }
}
