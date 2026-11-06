<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class UnlinkCommandTest extends E2ETestCase
{
    public function testUnlinkReplacesHardLinksWithPrivateCopiesAndLeavesTheStoreUntouched(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}', 'bin/run' => "#!/bin/sh\necho run"], ['bin/run']);
        $this->addPackage('acme/zed', '1.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/zed' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        $before = $this->storeFiles();
        $fooInode = fileinode($project.'/vendor/acme/lib/src/Foo.php');
        $zedInode = fileinode($project.'/vendor/acme/zed/src/Zed.php');

        [$exitCode, $output] = $this->composer($project, ['pcomposer:unlink', 'acme/lib']);

        self::assertSame(0, $exitCode, $output);
        self::assertNotSame($fooInode, fileinode($project.'/vendor/acme/lib/src/Foo.php'), 'new inode');
        self::assertNotContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertStringEqualsFile($project.'/vendor/acme/lib/src/Foo.php', '<?php class Foo {}');
        self::assertStringEqualsFile($project.'/vendor/acme/lib/bin/run', "#!/bin/sh\necho run");
        self::assertSame(0644, fileperms($project.'/vendor/acme/lib/src/Foo.php') & 0777);
        self::assertSame(0755, fileperms($project.'/vendor/acme/lib/bin/run') & 0777);
        self::assertSame(0644, fileperms($project.'/vendor/acme/lib/composer.json') & 0777);
        self::assertSame($before, $this->storeFiles(), 'store files keep inode, mode and content');
        self::assertSame($zedInode, fileinode($project.'/vendor/acme/zed/src/Zed.php'), 'other packages stay linked');
        self::assertSame('copy', $this->manifest($project)['acme/lib']['strategy']);
        self::assertSame('hardlink', $this->manifest($project)['acme/zed']['strategy']);
    }

    public function testUnlinkOfAnUnknownPackageFails(): void
    {
        $project = $this->createProject();
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['pcomposer:unlink', 'acme/nope']);

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString('acme/nope is not linked from the store', $output);
    }

    public function testUnlinkOfACopiedOrReflinkedPackageIsANoOp(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        foreach (['copy', 'reflink'] as $strategy) {
            $manifest = $this->manifest($project);
            $manifest['acme/lib']['strategy'] = $strategy;
            file_put_contents($project.'/vendor/composer/pcomposer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
            $inode = fileinode($project.'/vendor/acme/lib/src/Foo.php');

            [$exitCode, $output] = $this->composer($project, ['pcomposer:unlink', 'acme/lib']);

            self::assertSame(0, $exitCode, $output);
            self::assertStringContainsString("acme/lib is installed with the $strategy strategy, nothing to unlink", $output);
            self::assertSame($inode, fileinode($project.'/vendor/acme/lib/src/Foo.php'), 'files untouched');
            self::assertSame($strategy, $this->manifest($project)['acme/lib']['strategy']);
        }
    }

    /** @return array<string, array{int, int, string}> store file => [inode, mode, content] */
    private function storeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp.'/store/files', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $files[$path] = [$file->getInode(), $file->getPerms() & 0777, (string) file_get_contents($path)];
        }
        ksort($files);

        return $files;
    }
}
