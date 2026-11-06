<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ReflinkInstallTest extends E2ETestCase
{
    public function testInstalledFilesAreWritableClonesOfTheStore(): void
    {
        if ('Darwin' !== \PHP_OS_FAMILY) {
            self::markTestSkipped('cp -c clone semantics are asserted on macOS (APFS) only');
        }
        $this->addPackage('acme/lib', '1.0.0', [
            'src/Foo.php' => '<?php class Foo {}',
            'bin/run' => "#!/bin/sh\necho hi\n",
        ], ['bin/run']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'reflink']]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('falling back', $output);
        $storeInodes = $this->storeInodes();
        foreach (['src/Foo.php' => 0644, 'bin/run' => 0755] as $file => $mode) {
            $stat = stat($project.'/vendor/acme/lib/'.$file);
            self::assertNotFalse($stat);
            self::assertNotContains($stat['ino'], $storeInodes, "$file shares an inode with the store");
            self::assertSame($mode, $stat['mode'] & 0777, "$file mode");
        }
        self::assertSame('<?php class Foo {}', file_get_contents($project.'/vendor/acme/lib/src/Foo.php'));
        self::assertSame('reflink', $this->manifest($project)['acme/lib']['strategy']);

        file_put_contents($project.'/vendor/acme/lib/src/Foo.php', '<?php class Foo { public $edited; }');

        $storeFile = $this->tmp.'/store/files/'.substr(hash('sha256', '<?php class Foo {}'), 0, 2).'/'.hash('sha256', '<?php class Foo {}');
        self::assertSame('<?php class Foo {}', file_get_contents($storeFile), 'editing vendor must not reach the store');
    }

    public function testAFilesystemWithoutCloneSupportFallsBackToCopyWithOneWarning(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            self::markTestSkipped('only Linux cp fails loudly without clone support');
        }
        file_put_contents($this->tmp.'/probe', 'x');
        exec('cp --reflink=always '.escapeshellarg($this->tmp.'/probe').' '.escapeshellarg($this->tmp.'/probe-clone').' 2>/dev/null', $lines, $code);
        if (0 === $code) {
            self::markTestSkipped($this->tmp.' supports reflink');
        }
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/other', '1.0.0', ['src/Bar.php' => '<?php class Bar {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/other' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'reflink']]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(1, substr_count($output, 'falling back to copy for this run'), $output);
        self::assertStringContainsString('pcomposer: reflink failed (', $output);
        self::assertSame(['copy', 'copy'], array_column($this->manifest($project), 'strategy'));
        self::assertSame(0644, fileperms($project.'/vendor/acme/lib/src/Foo.php') & 0777);
    }
}
