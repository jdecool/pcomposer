<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class CopyFallbackTest extends E2ETestCase
{
    private ?string $otherFs = null;

    protected function tearDown(): void
    {
        if (null !== $this->otherFs) {
            exec('rm -rf '.escapeshellarg($this->otherFs));
        }
        parent::tearDown();
    }

    public function testAStoreOnAnotherFilesystemFallsBackToCopyWithOneWarning(): void
    {
        $store = $this->storeOnAnotherFilesystem();
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPackage('acme/other', '1.0.0', ['src/Bar.php' => '<?php class Bar {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0', 'acme/other' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install'], ['PCOMPOSER_STORE' => $store]);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(1, substr_count($output, 'falling back to copy for this run'), $output);
        self::assertStringContainsString('pcomposer: hardlink failed (', $output);
        /** @var array<string, array{strategy: string}> $manifest */
        $manifest = json_decode((string) file_get_contents($project.'/vendor/composer/pcomposer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['copy', 'copy'], array_column($manifest, 'strategy'));
        self::assertSame(0644, fileperms($project.'/vendor/acme/lib/src/Foo.php') & 0777);
    }

    /** A writable directory on a device other than the fixture's; skips the test when the machine offers none. */
    private function storeOnAnotherFilesystem(): string
    {
        $device = self::device($this->tmp);
        foreach (['/dev/shm', '/mnt', '/tmp', sys_get_temp_dir(), ...(glob('/Volumes/*') ?: [])] as $candidate) {
            if (!is_dir($candidate) || !is_writable($candidate) || self::device($candidate) === $device) {
                continue;
            }
            $this->otherFs = $candidate.'/pcomposer-xfs-'.bin2hex(random_bytes(6));
            if (@mkdir($this->otherFs, 0777, true)) {
                return $this->otherFs;
            }
        }
        self::markTestSkipped('no writable filesystem other than the one holding '.$this->tmp);
    }

    private static function device(string $path): int
    {
        $stat = stat($path);
        self::assertNotFalse($stat);

        return $stat['dev'];
    }
}
