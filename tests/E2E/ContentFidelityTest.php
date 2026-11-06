<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ContentFidelityTest extends E2ETestCase
{
    public function testDeclaredBinFilesArePrivateExecutableCopies(): void
    {
        $this->addPackage('acme/tool', '1.0.0', [
            'src/Foo.php' => '<?php class Foo {}',
            'bin/run' => "#!/bin/sh\necho hi\n",
        ], ['bin/run'], ['bin' => ['bin/run']]);
        $project = $this->createProject(['acme/tool' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $storeInodes = $this->storeInodes();
        $bin = $project.'/vendor/acme/tool/bin/run';
        self::assertNotContains(fileinode($bin), $storeInodes, 'bin file must be a private copy');
        self::assertTrue(is_executable($bin));
        self::assertSame("#!/bin/sh\necho hi\n", file_get_contents($bin));
        self::assertContains(fileinode($project.'/vendor/acme/tool/src/Foo.php'), $storeInodes);
        $storeBin = (array) glob($this->tmp.'/store/files/*/*-x');
        self::assertCount(1, $storeBin);
        self::assertSame(0555, fileperms((string) $storeBin[0]) & 0777, 'store file must keep 0555');
    }

    public function testEmptyDirectoriesAndSymlinksAreRecreated(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}'], [], [], ['src/Alias.php' => 'Foo.php', 'lib' => 'src'], ['var/cache']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $pkg = $project.'/vendor/acme/lib';
        self::assertDirectoryExists($pkg.'/var/cache');
        self::assertTrue(is_link($pkg.'/src/Alias.php'), 'file symlink');
        self::assertSame('Foo.php', readlink($pkg.'/src/Alias.php'));
        self::assertTrue(is_link($pkg.'/lib'), 'directory symlink');
        self::assertSame('src', readlink($pkg.'/lib'));
        self::assertSame('<?php class Foo {}', file_get_contents($pkg.'/lib/Alias.php'));
        self::assertContains(fileinode($pkg.'/src/Foo.php'), $this->storeInodes());
    }

    public function testNonEmptyTargetFallsThroughToComposer(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);
        mkdir($project.'/vendor/acme/lib', 0777, true);
        file_put_contents($project.'/vendor/acme/lib/stale.txt', 'left over');

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Extracting archive', $output);
        self::assertFileExists($project.'/vendor/acme/lib/src/Foo.php');
        self::assertDirectoryDoesNotExist($this->tmp.'/store', 'nothing should have been ingested');
        self::assertSame('{}', trim((string) file_get_contents($project.'/vendor/composer/pcomposer.json')));
    }

    public function testComposerStatusReportsOnlyRealModifications(): void
    {
        // Global plugin from a zip: the `path`-symlinked plugin would itself show up as modified in a dirty checkout.
        $this->addPluginPackage('1.0.0');
        $this->installPluginGlobally('1.0.0');
        $this->addPackage('acme/tool', '1.0.0', [
            'src/Foo.php' => '<?php class Foo {}',
            'bin/run' => "#!/bin/sh\necho hi\n",
        ], ['bin/run'], ['bin' => ['bin/run']], ['src/Alias.php' => 'Foo.php'], ['var/cache']);
        $project = $this->createBareProject(['acme/tool' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['status']);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('No local changes', $output);

        file_put_contents($project.'/vendor/acme/tool/bin/run', "#!/bin/sh\necho edited\n");
        [$exitCode, $output] = $this->composer($project, ['status']);
        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('acme/tool', $output);
        self::assertFileExists($project.'/vendor/acme/tool/src/Foo.php');
        self::assertDirectoryDoesNotExist($project.'/vendor/acme/tool_compare');
    }
}
