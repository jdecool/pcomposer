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

    /** @return list<int> inodes of every regular file under the store */
    private function storeInodes(): array
    {
        $inodes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp.'/store', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $inodes[] = $file->getInode();
            }
        }

        return $inodes;
    }
}
