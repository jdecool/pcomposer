<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class HardlinkInstallTest extends E2ETestCase
{
    public function testInstalledFilesAreReadOnlyHardLinksToTheStore(): void
    {
        $this->addPackage('acme/lib', '1.0.0', [
            'src/Foo.php' => '<?php class Foo {}',
            'bin/run' => "#!/bin/sh\necho hi\n",
        ], ['bin/run']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $storeInodes = $this->storeInodes();
        foreach (['src/Foo.php' => 0444, 'bin/run' => 0555, 'composer.json' => 0444] as $file => $mode) {
            $stat = stat($project.'/vendor/acme/lib/'.$file);
            self::assertNotFalse($stat);
            self::assertContains($stat['ino'], $storeInodes, "$file is not linked to the store");
            self::assertSame($mode, $stat['mode'] & 0777, "$file mode");
        }
    }

    public function testTwoProjectsSharingTheStoreShareInodes(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $a = $this->createProject(['acme/lib' => '1.0.0'], [], 'a');
        $b = $this->createProject(['acme/lib' => '1.0.0'], [], 'b');

        [$exitCode, $output] = $this->composer($a, ['install']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer($b, ['install']);
        self::assertSame(0, $exitCode, $output);

        self::assertSame(fileinode($a.'/vendor/acme/lib/src/Foo.php'), fileinode($b.'/vendor/acme/lib/src/Foo.php'));
    }

    public function testASecondProjectLinksFromTheWarmStoreWithoutExtractingAgain(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $a = $this->createProject(['acme/lib' => '1.0.0'], [], 'a');
        $b = $this->createProject(['acme/lib' => '1.0.0'], [], 'b');
        [$exitCode, $output] = $this->composer($a, ['install']);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Installing acme/lib (1.0.0): Linking from store', $output);
        self::assertStringNotContainsString('(cached)', $output);
        $index = (array) glob($this->tmp.'/store/index/acme/lib@*.json');
        self::assertCount(1, $index);
        $indexInode = fileinode((string) $index[0]);

        [$exitCode, $output] = $this->composer($b, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Installing acme/lib (1.0.0): Linking from store (cached)', $output);
        self::assertSame($indexInode, fileinode((string) $index[0]), 'index entry was rewritten: the package was ingested again');
        self::assertSame(fileinode($a.'/vendor/acme/lib/src/Foo.php'), fileinode($b.'/vendor/acme/lib/src/Foo.php'));
    }

    public function testTwoVersionsOfAPackageShareUnchangedFiles(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}', 'src/Bar.php' => '<?php class Bar {}']);
        $this->addPackage('acme/lib', '2.0.0', ['src/Foo.php' => '<?php class Foo {}', 'src/Bar.php' => '<?php class Bar { public $v2; }']);
        $a = $this->createProject(['acme/lib' => '1.0.0'], [], 'a');
        $b = $this->createProject(['acme/lib' => '2.0.0'], [], 'b');

        [$exitCode, $output] = $this->composer($a, ['install']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer($b, ['install']);
        self::assertSame(0, $exitCode, $output);

        self::assertSame(fileinode($a.'/vendor/acme/lib/src/Foo.php'), fileinode($b.'/vendor/acme/lib/src/Foo.php'));
        self::assertNotSame(fileinode($a.'/vendor/acme/lib/src/Bar.php'), fileinode($b.'/vendor/acme/lib/src/Bar.php'));
        self::assertStringContainsString('v2', (string) file_get_contents($b.'/vendor/acme/lib/src/Bar.php'));
    }

    public function testInstalledProjectAutoloadsAClassFromALinkedPackage(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Bar.php' => '<?php namespace Foo; class Bar {}'], [], [
            'autoload' => ['psr-4' => ['Foo\\' => 'src/']],
        ]);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        exec(\sprintf('cd %s && php -r %s 2>&1', escapeshellarg($project), escapeshellarg('require "vendor/autoload.php"; new Foo\Bar; echo "ok";')), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame(['ok'], $lines);
    }

    public function testPathRepositoryPackagesAreLeftToComposer(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $local = $this->addPathPackage('acme/local', '1.0.0', ['src/Local.php' => '<?php class Local {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/local' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        self::assertTrue(is_link($project.'/vendor/acme/local'), 'path package should be symlinked by Composer');
        self::assertSame(realpath($local), realpath($project.'/vendor/acme/local'));
        self::assertNotContains(fileinode($local.'/src/Local.php'), $this->storeInodes());
        self::assertContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
    }
}
