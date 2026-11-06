<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ConfigurationTest extends E2ETestCase
{
    public function testDisabledInComposerJsonInstallsThroughComposerAndWritesNoManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['enabled' => false]]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertTrue(is_writable($project.'/vendor/acme/lib/src/Foo.php'), 'extracted by Composer, not linked');
        self::assertDirectoryDoesNotExist($this->tmp.'/store');
        self::assertFileDoesNotExist($project.'/vendor/composer/pcomposer.json');
    }

    public function testDisabledByEnvironmentInstallsThroughComposerAndWritesNoManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install'], ['PCOMPOSER_DISABLE' => '1']);

        self::assertSame(0, $exitCode, $output);
        self::assertTrue(is_writable($project.'/vendor/acme/lib/src/Foo.php'), 'extracted by Composer, not linked');
        self::assertDirectoryDoesNotExist($this->tmp.'/store');
        self::assertFileDoesNotExist($project.'/vendor/composer/pcomposer.json');
    }

    public function testAnUnknownStrategyFailsTheInstallWithAClearMessage(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'symlink']]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('pcomposer: invalid strategy "symlink", expected one of hardlink, reflink, copy', $output);
        self::assertFileDoesNotExist($project.'/vendor/acme/lib/src/Foo.php');
    }

    public function testCopyStrategyMaterialisesWritableCopiesAndRecordsCopyInTheManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}', 'bin/run' => "#!/bin/sh\n"], ['bin/run']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'copy']]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $storeInodes = $this->storeInodes();
        self::assertNotEmpty($storeInodes, 'the package is still ingested into the store');
        foreach (['src/Foo.php' => 0644, 'bin/run' => 0755] as $file => $mode) {
            $stat = stat($project.'/vendor/acme/lib/'.$file);
            self::assertNotFalse($stat);
            self::assertNotContains($stat['ino'], $storeInodes, "$file is linked to the store");
            self::assertSame($mode, $stat['mode'] & 0777, "$file mode");
        }
        self::assertSame('copy', $this->manifest($project)['acme/lib']['strategy']);
    }

    public function testStrategyFromTheEnvironmentOverridesComposerJson(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'hardlink']]]);

        [$exitCode, $output] = $this->composer($project, ['install'], ['PCOMPOSER_STRATEGY' => 'copy']);

        self::assertSame(0, $exitCode, $output);
        self::assertNotContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertSame('copy', $this->manifest($project)['acme/lib']['strategy']);
    }

    public function testPackagesListedInExtraPcomposerCopyAreCopiedWhileOthersAreLinked(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/writer', '1.0.0', ['src/W.php' => '<?php class W {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/writer' => '1.0.0'], ['extra' => ['pcomposer' => ['copy' => ['acme/writer']]]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertNotContains(fileinode($project.'/vendor/acme/writer/src/W.php'), $this->storeInodes());
        self::assertSame(0644, fileperms($project.'/vendor/acme/writer/src/W.php') & 0777);
        self::assertSame(['acme/lib' => 'hardlink', 'acme/writer' => 'copy'], array_map(static fn (array $e): string => $e['strategy'], $this->manifest($project)));
    }

    public function testPackagesWithPatchesInExtraPatchesAreCopied(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/patched', '1.0.0', ['src/P.php' => '<?php class P {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/patched' => '1.0.0'], ['extra' => ['patches' => ['acme/patched' => ['fix' => 'patches/fix.patch']]]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertNotContains(fileinode($project.'/vendor/acme/patched/src/P.php'), $this->storeInodes());
        self::assertSame(['acme/lib' => 'hardlink', 'acme/patched' => 'copy'], array_map(static fn (array $e): string => $e['strategy'], $this->manifest($project)));
    }

    public function testStoreEnvironmentVariableRelocatesTheStore(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);
        $store = $this->tmp.'/elsewhere/my-store';

        [$exitCode, $output] = $this->composer($project, ['install'], ['PCOMPOSER_STORE' => $store]);

        self::assertSame(0, $exitCode, $output);
        self::assertDirectoryDoesNotExist($this->tmp.'/store');
        self::assertDirectoryDoesNotExist($this->tmp.'/cache/pcomposer-store');
        $inode = fileinode($project.'/vendor/acme/lib/src/Foo.php');
        $found = false;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($store.'/files', \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $found = $found || $file->getInode() === $inode;
        }
        self::assertTrue($found, 'installed file is not hard-linked into the relocated store');
    }

    /** @return array<string, array{name: string, version: string, reference: string, strategy: string}> */
    private function manifest(string $project): array
    {
        /** @var array<string, array{name: string, version: string, reference: string, strategy: string}> $manifest */
        $manifest = json_decode((string) file_get_contents($project.'/vendor/composer/pcomposer.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $manifest;
    }
}
