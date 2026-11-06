<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ManifestTest extends E2ETestCase
{
    public function testInstallWritesTheManifestForLinkedPackagesOnly(): void
    {
        $this->addPackage('acme/zed', '1.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPathPackage('acme/local', '1.0.0', ['src/Local.php' => '<?php class Local {}']);
        $project = $this->createProject(['acme/zed' => '1.0.0', 'acme/lib' => '1.0.0', 'acme/local' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $json = (string) file_get_contents($project.'/vendor/composer/pcomposer.json');
        self::assertSame([
            'acme/lib' => ['name' => 'acme/lib', 'version' => '1.0.0', 'reference' => $this->zipSha1('acme/lib', '1.0.0'), 'strategy' => 'hardlink'],
            'acme/zed' => ['name' => 'acme/zed', 'version' => '1.0.0', 'reference' => $this->zipSha1('acme/zed', '1.0.0'), 'strategy' => 'hardlink'],
        ], json_decode($json, true, 512, \JSON_THROW_ON_ERROR), 'entries keyed and sorted by name; path package absent');
        self::assertStringStartsWith("{\n    \"acme/lib\": {\n        \"name\"", $json, 'pretty-printed');
    }

    public function testUpdateReplacesChangedFilesKeepsUnchangedInodesAndUpdatesTheManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}', 'src/Bar.php' => '<?php class Bar {}']);
        $project = $this->createProject(['acme/lib' => '^1.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        $fooInode = fileinode($project.'/vendor/acme/lib/src/Foo.php');
        $this->addPackage('acme/lib', '1.1.0', ['src/Foo.php' => '<?php class Foo {}', 'src/Bar.php' => '<?php class Bar { public $v2; }']);

        [$exitCode, $output] = $this->composer($project, ['update']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame($fooInode, fileinode($project.'/vendor/acme/lib/src/Foo.php'), 'unchanged file keeps its inode');
        self::assertStringContainsString('v2', (string) file_get_contents($project.'/vendor/acme/lib/src/Bar.php'));
        self::assertSame(
            ['name' => 'acme/lib', 'version' => '1.1.0', 'reference' => $this->zipSha1('acme/lib', '1.1.0'), 'strategy' => 'hardlink'],
            $this->manifest($project)['acme/lib'],
        );
    }

    public function testRemoveDeletesTheVendorDirKeepsTheStoreAndDropsTheManifestEntry(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/zed', '1.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/zed' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        $storeFiles = $this->storeFiles();

        [$exitCode, $output] = $this->composer($project, ['remove', 'acme/lib']);

        self::assertSame(0, $exitCode, $output);
        self::assertDirectoryDoesNotExist($project.'/vendor/acme/lib');
        self::assertSame($storeFiles, $this->storeFiles(), 'store left intact');
        self::assertSame(['acme/zed'], array_keys($this->manifest($project)));
    }

    public function testTheManifestIsWrittenWithoutScripts(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/zed', '1.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/zed' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install', '--no-scripts']);
        self::assertSame(0, $exitCode, $output);
        self::assertSame(['acme/lib', 'acme/zed'], array_keys($this->manifest($project)));

        [$exitCode, $output] = $this->composer($project, ['remove', 'acme/lib', '--no-scripts']);
        self::assertSame(0, $exitCode, $output);
        self::assertSame(['acme/zed'], array_keys($this->manifest($project)));
    }

    public function testRemovingAPackageStillLinkedByAnotherProjectLeavesThatProjectWorking(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Bar.php' => '<?php namespace Foo; class Bar {}'], [], [
            'autoload' => ['psr-4' => ['Foo\\' => 'src/']],
        ]);
        $a = $this->createProject(['acme/lib' => '1.0.0'], [], 'a');
        $b = $this->createProject(['acme/lib' => '1.0.0'], [], 'b');
        [$exitCode, $output] = $this->composer($a, ['install']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer($b, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($a, ['remove', 'acme/lib']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringEqualsFile($b.'/vendor/acme/lib/src/Bar.php', '<?php namespace Foo; class Bar {}');
        exec(\sprintf('cd %s && php -r %s 2>&1', escapeshellarg($b), escapeshellarg('require "vendor/autoload.php"; new Foo\Bar; echo "ok";')), $lines, $code);
        self::assertSame(['ok'], $lines, implode("\n", $lines));
        self::assertSame(['acme/lib'], array_keys($this->manifest($b)));
    }

    /** @return list<string> every regular file under the store, relative to it */
    private function storeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp.'/store', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($this->tmp.'/store/'));
            }
        }
        sort($files);

        return $files;
    }

    /** Artifact packages have no dist reference: Composer uses the zip's sha1 instead. */
    private function zipSha1(string $name, string $version): string
    {
        return sha1_file($this->tmp.'/artifacts/'.str_replace('/', '-', $name).'-'.$version.'.zip') ?: '';
    }
}
