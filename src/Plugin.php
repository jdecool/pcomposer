<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

use Composer\Cache;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        if (Platform::isWindows()) {
            return;
        }

        $config = $composer->getConfig();
        $cacheDir = $config->get('cache-dir');
        \assert(\is_string($cacheDir));
        $store = new Store(Platform::getEnv('PCOMPOSER_STORE') ?: $cacheDir.'/pcomposer-store');

        // Same wiring as Composer\Factory::createDownloadManager for the stock zip downloader.
        $cache = null;
        if ($config->get('cache-files-ttl') > 0) {
            $cacheFilesDir = $config->get('cache-files-dir');
            \assert(\is_string($cacheFilesDir));
            $cache = new Cache($io, $cacheFilesDir, 'a-z0-9_./');
            $cache->setReadOnly((bool) $config->get('cache-read-only'));
        }
        $process = $composer->getLoop()->getProcessExecutor();

        $composer->getDownloadManager()->setDownloader('zip', new StoreZipDownloader(
            $store,
            $io,
            $config,
            $composer->getLoop()->getHttpDownloader(),
            $composer->getEventDispatcher(),
            $cache,
            new Filesystem($process),
            $process,
        ));
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
