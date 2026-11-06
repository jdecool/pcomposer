<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

use Composer\Cache;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private ?Manifest $manifest = null;

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'writeManifest',
            ScriptEvents::POST_UPDATE_CMD => 'writeManifest',
        ];
    }

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => Command\CommandProvider::class];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        if (Platform::isWindows()) {
            return;
        }

        $config = $composer->getConfig();
        $cacheDir = $config->get('cache-dir');
        \assert(\is_string($cacheDir));
        $store = new Store(Platform::getEnv('PCOMPOSER_STORE') ?: $cacheDir.'/pcomposer-store');
        $vendorDir = $config->get('vendor-dir');
        \assert(\is_string($vendorDir));
        $this->manifest = Manifest::inVendorDir($vendorDir);

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
            $this->manifest,
            $composer->getInstallationManager(),
            $io,
            $config,
            $composer->getLoop()->getHttpDownloader(),
            $composer->getEventDispatcher(),
            $cache,
            new Filesystem($process),
            $process,
        ));
    }

    /** Runs after every install/update batch (`composer remove` fires post-update-cmd too). */
    public function writeManifest(Event $event): void
    {
        $this->manifest?->write($event->getComposer()->getRepositoryManager()->getLocalRepository());
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
