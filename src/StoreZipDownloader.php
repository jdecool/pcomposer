<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

use Composer\Cache;
use Composer\Config as ComposerConfig;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Downloader\ZipDownloader;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use React\Promise\PromiseInterface;
use Symfony\Component\Finder\Finder;

/**
 * Composer's zip downloader with the install step replaced: the archive is
 * extracted next to the target, ingested into the store, then materialised into place
 * with the strategy Config picks for the package.
 */
class StoreZipDownloader extends ZipDownloader
{
    private bool $fallbackWarned = false;

    public function __construct(
        private readonly Config $pcomposerConfig,
        private readonly Store $store,
        private readonly Manifest $manifest,
        private readonly InstallationManager $installationManager,
        IOInterface $io,
        ComposerConfig $config,
        HttpDownloader $httpDownloader,
        ?EventDispatcher $eventDispatcher = null,
        ?Cache $cache = null,
        ?Filesystem $filesystem = null,
        ?ProcessExecutor $process = null,
    ) {
        parent::__construct($io, $config, $httpDownloader, $eventDispatcher, $cache, $filesystem, $process);
    }

    public function install(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        // A non-empty target (shared-path installers, create-project in ".") is the parent's merge case: not ours.
        if (is_dir($path) && !$this->filesystem->isDirEmpty($path)) {
            return parent::install($package, $path, $output);
        }

        $key = self::storeKey($package);
        $cached = $this->store->has($key);
        if ($output) {
            $this->io->writeError('  - '.InstallOperation::format($package).': Linking from store'.($cached ? ' (cached)' : ''));
        }

        $this->filesystem->emptyDirectory($path);
        $fileName = $this->getFileName($package, $path);
        if ($cached) {
            if (file_exists($fileName)) {
                $this->filesystem->unlink($fileName);
            }
            $this->materialise($package, $key, $path);

            return \React\Promise\resolve(null);
        }

        do {
            $temporaryDir = \dirname($path).'/.pcomposer-'.bin2hex(random_bytes(4));
        } while (is_dir($temporaryDir));
        $this->addCleanupPath($package, $temporaryDir);
        $this->addCleanupPath($package, $path);
        $this->filesystem->ensureDirectoryExists($temporaryDir);

        $cleanup = function () use ($package, $path, $temporaryDir): void {
            $this->clearLastCacheWrite($package);
            $this->filesystem->removeDirectory($temporaryDir);
            $this->filesystem->removeDirectory($path);
            $this->removeCleanupPath($package, $temporaryDir);
            $this->removeCleanupPath($package, $path);
        };

        try {
            $promise = $this->extract($package, $fileName, $temporaryDir);
        } catch (\Exception $e) {
            $cleanup();
            throw $e;
        }

        return $promise->then(function () use ($package, $key, $path, $temporaryDir, $fileName, $cleanup): void {
            try {
                if (file_exists($fileName)) {
                    $this->filesystem->unlink($fileName);
                }
                $this->store->ingest($key, self::packageRoot($temporaryDir));
                $this->materialise($package, $key, $path);
                $this->filesystem->removeDirectory($temporaryDir);
                $this->removeCleanupPath($package, $temporaryDir);
                $this->removeCleanupPath($package, $path);
            } catch (\Throwable $e) {
                $cleanup();
                throw $e;
            }
        });
    }

    /** Materialises the stored $key into $path with the configured strategy and records the result in the manifest. */
    private function materialise(PackageInterface $package, string $key, string $path): void
    {
        $wanted = $this->pcomposerConfig->strategyFor($package->getName());
        $strategy = $this->store->materialise($key, $path, $wanted, $package->getBinaries());
        if (!$this->fallbackWarned && null !== ($reason = $this->store->fallbackReason())) {
            $this->fallbackWarned = true;
            $this->io->writeError("<warning>pcomposer: $wanted failed ($reason), falling back to copy for this run</warning>");
        }
        // `composer status` installs into `<path>_compare` to diff: only the real install path counts.
        if ($this->filesystem->normalizePath($path) === $this->filesystem->normalizePath((string) $this->installationManager->getInstallPath($package))) {
            $this->manifest->record($package, $strategy);
        }
    }

    /** `vendor/name@version-reference`; artifact packages have no reference, their zip shasum stands in. */
    public static function storeKey(PackageInterface $package): string
    {
        return $package->getName().'@'.$package->getVersion().'-'.($package->getDistReference() ?? $package->getDistSha1Checksum() ?? '');
    }

    /** Unwraps a single top-level directory (GitHub zipballs), ignoring OS metadata siblings. */
    private static function packageRoot(string $extractedDir): string
    {
        $entries = iterator_to_array(Finder::create()->ignoreVCS(false)->ignoreDotFiles(false)->depth(0)->notName(Platform::METADATA_NAME_REGEX)->in($extractedDir));
        if (1 === \count($entries) && is_dir((string) reset($entries))) {
            return (string) reset($entries);
        }

        return $extractedDir;
    }
}
