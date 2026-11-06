<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class StatusCommandTest extends E2ETestCase
{
    public function testCommandsAreListedByComposer(): void
    {
        $project = $this->createProject();
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['list']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('pcomposer:status', $output);
        self::assertStringContainsString('pcomposer:unlink', $output);
    }

    public function testStatusListsTheManifestWithStorePathAndSize(): void
    {
        $this->addPackage('acme/lib', '1.2.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/zed', '2.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $project = $this->createProject(['acme/lib' => '1.2.0', 'acme/zed' => '2.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['pcomposer:status']);

        self::assertSame(0, $exitCode, $output);
        self::assertMatchesRegularExpression('/acme\/lib\s+\|\s+1\.2\.0\s+\|\s+hardlink/', $output);
        self::assertMatchesRegularExpression('/acme\/zed\s+\|\s+2\.0\.0\s+\|\s+hardlink/', $output);
        self::assertStringContainsString($this->tmp.'/store', $output);
        self::assertStringContainsString($this->storeSize().' bytes', $output);
        self::assertGreaterThan(0, $this->storeSize());
    }

    public function testStatusWithoutManifestSaysSoAndExitsZero(): void
    {
        $project = $this->createProject();
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        unlink($project.'/vendor/composer/pcomposer.json');

        [$exitCode, $output] = $this->composer($project, ['pcomposer:status']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('No package linked from the store', $output);
    }

    /** Sum of the sizes of the files under the store's `files/` dir. */
    private function storeSize(): int
    {
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp.'/store/files', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $size += $file->getSize();
        }

        return $size;
    }
}
