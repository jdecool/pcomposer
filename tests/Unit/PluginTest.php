<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\NullIO;
use Composer\Package\RootPackage;
use JDecool\Composer\Plugin\PComposer\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testOnWindowsActivationRegistersNoDownloader(): void
    {
        $composer = new Composer();
        $composer->setConfig(new Config());
        $composer->setPackage(new RootPackage('fixture/project', '1.0.0.0', '1.0.0'));
        $composer->setDownloadManager(new DownloadManager(new NullIO()));

        (new Plugin(isWindows: true))->activate($composer, new NullIO());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown downloader type: zip');
        $composer->getDownloadManager()->getDownloader('zip');
    }
}
