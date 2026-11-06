<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\Unit;

use JDecool\Composer\Plugin\PComposer\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pcomposer-store-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/pkg/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w '.escapeshellarg($this->tmp).' && rm -rf '.escapeshellarg($this->tmp));
    }

    public function testSameContentWithDifferentExecutableBitsAreDistinctEntries(): void
    {
        file_put_contents($this->tmp.'/pkg/a.sh', "#!/bin/sh\n");
        file_put_contents($this->tmp.'/pkg/bin/a.sh', "#!/bin/sh\n");
        chmod($this->tmp.'/pkg/bin/a.sh', 0755);
        $store = new Store($this->tmp.'/store');

        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');
        $store->link('acme/lib@1.0.0.0-ref', $this->tmp.'/out');

        self::assertNotSame(fileinode($this->tmp.'/out/a.sh'), fileinode($this->tmp.'/out/bin/a.sh'));
        self::assertSame(0444, fileperms($this->tmp.'/out/a.sh') & 0777);
        self::assertSame(0555, fileperms($this->tmp.'/out/bin/a.sh') & 0777);
    }

    public function testAHostileReferenceCannotEscapeTheIndexDirectory(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        $store = new Store($this->tmp.'/store');

        $store->ingest("acme/lib@1.0.0.0-/x\0y/z", $this->tmp.'/pkg');
        $store->link("acme/lib@1.0.0.0-/x\0y/z", $this->tmp.'/out');

        self::assertFileExists($this->tmp.'/out/a.txt');
        self::assertCount(1, (array) glob($this->tmp.'/store/index/acme/*.json'));
        self::assertDirectoryDoesNotExist($this->tmp.'/store/index/acme/lib@1.0.0.0-');

        $this->expectException(\InvalidArgumentException::class);
        $store->ingest('acme/lib@1.0.0.0-../../x', $this->tmp.'/pkg');
    }

    public function testAnExistingStoreFileIsNeverRewritten(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        $store = new Store($this->tmp.'/store');
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');
        $store->link('acme/lib@1.0.0.0-ref', $this->tmp.'/out1');
        $inode = fileinode($this->tmp.'/out1/a.txt');

        $store->ingest('acme/lib@2.0.0.0-ref', $this->tmp.'/pkg');
        $store->link('acme/lib@2.0.0.0-ref', $this->tmp.'/out2');

        self::assertSame($inode, fileinode($this->tmp.'/out2/a.txt'), 'second ingest replaced the store file');
    }

    public function testConcurrentIngestOfTheSameFileKeepsTheFirstInode(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        $hash = hash('sha256', 'content');
        $storeFile = $this->tmp.'/store/files/'.substr($hash, 0, 2).'/'.$hash;
        mkdir(\dirname($storeFile), 0777, true);
        file_put_contents($storeFile, 'content'); // another process finished first
        chmod($storeFile, 0444);
        $inode = fileinode($storeFile);

        (new Store($this->tmp.'/store'))->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        clearstatcache();
        self::assertSame($inode, fileinode($storeFile), 'the second ingest replaced the store file');
        self::assertSame([], array_values(array_diff((array) scandir(\dirname($storeFile)), ['.', '..', $hash])), 'temp file left behind');
    }

    public function testAFailingFirstLinkFallsBackToCopyingTheWholePackage(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        file_put_contents($this->tmp.'/pkg/bin/b.sh', "#!/bin/sh\n");
        chmod($this->tmp.'/pkg/bin/b.sh', 0755);
        $store = new Store($this->tmp.'/store', static fn (): bool => false);
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        self::assertSame('copy', $store->materialise('acme/lib@1.0.0.0-ref', $this->tmp.'/out', 'hardlink'));

        self::assertNotNull($store->fallbackReason());
        self::assertSame('content', file_get_contents($this->tmp.'/out/a.txt'));
        self::assertSame(0644, fileperms($this->tmp.'/out/a.txt') & 0777);
        self::assertSame(0755, fileperms($this->tmp.'/out/bin/b.sh') & 0777);
        self::assertNotSame(fileinode($this->tmp.'/store/files/'.substr(hash('sha256', 'content'), 0, 2).'/'.hash('sha256', 'content')), fileinode($this->tmp.'/out/a.txt'));
        self::assertSame(['a.txt', 'bin'], array_values(array_diff((array) scandir($this->tmp.'/out'), ['.', '..'])), 'nothing but the package in the target');
    }

    public function testALinkFailingAfterTheFirstFileIsAnError(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'a');
        file_put_contents($this->tmp.'/pkg/b.txt', 'b');
        $calls = 0;
        $store = new Store($this->tmp.'/store', static function (string $source, string $dest) use (&$calls): bool {
            return ++$calls <= 2 && link($source, $dest); // probe + first file succeed, second file fails
        });
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot materialise');
        $store->materialise('acme/lib@1.0.0.0-ref', $this->tmp.'/out', 'hardlink');
    }

    public function testAFailingFirstCloneFallsBackToCopyingTheWholePackage(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        $store = new Store($this->tmp.'/store', null, static fn (): bool => false);
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        self::assertSame('copy', $store->materialise('acme/lib@1.0.0.0-ref', $this->tmp.'/out', 'reflink'));

        self::assertNotNull($store->fallbackReason());
        self::assertSame('content', file_get_contents($this->tmp.'/out/a.txt'));
        self::assertSame(['a.txt', 'bin'], array_values(array_diff((array) scandir($this->tmp.'/out'), ['.', '..'])), 'nothing but the package in the target');
    }

    public function testReflinkClonesOncePerDirectoryThenRenamesToPackageNames(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'a');
        mkdir($this->tmp.'/pkg/sub');
        file_put_contents($this->tmp.'/pkg/sub/b.txt', 'b');
        file_put_contents($this->tmp.'/pkg/sub/same.txt', 'b'); // same content as b.txt: one store file, two targets
        file_put_contents($this->tmp.'/pkg/sub/run.sh', "#!/bin/sh\n");
        chmod($this->tmp.'/pkg/sub/run.sh', 0755);
        file_put_contents($this->tmp.'/pkg/bin/tool', "#!/bin/sh\n");
        chmod($this->tmp.'/pkg/bin/tool', 0755);
        $batches = [];
        $cloner = static function (array $sources, string $dir) use (&$batches): bool {
            $batches[] = [$sources, $dir];
            foreach ($sources as $source) {
                \assert(\is_string($source));
                copy($source, $dir.'/'.basename($source));
            }

            return true;
        };
        $store = new Store($this->tmp.'/store', null, $cloner);
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        self::assertSame('reflink', $store->materialise('acme/lib@1.0.0.0-ref', $this->tmp.'/out', 'reflink', ['bin/tool']));

        self::assertNull($store->fallbackReason());
        self::assertSame([$this->tmp.'/out', $this->tmp.'/out', $this->tmp.'/out/sub'], array_column($batches, 1), 'probe, then one batch per directory; bin/ is copied');
        self::assertCount(2, $batches[2][0], 'duplicate content cloned once');
        foreach (['a.txt' => ['a', 0644], 'sub/b.txt' => ['b', 0644], 'sub/same.txt' => ['b', 0644], 'sub/run.sh' => ["#!/bin/sh\n", 0755], 'bin/tool' => ["#!/bin/sh\n", 0755]] as $file => [$content, $mode]) {
            self::assertSame($content, file_get_contents($this->tmp.'/out/'.$file), $file);
            self::assertSame($mode, fileperms($this->tmp.'/out/'.$file) & 0777, "$file mode");
        }
        self::assertSame(['b.txt', 'run.sh', 'same.txt'], array_values(array_diff((array) scandir($this->tmp.'/out/sub'), ['.', '..'])), 'no hash-named leftovers');
    }

    public function testPruneSparesOrphansYoungerThanTheGracePeriod(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'fresh');
        file_put_contents($this->tmp.'/pkg/b.txt', 'old');
        $store = new Store($this->tmp.'/store');
        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');
        unlink($this->tmp.'/store/index/acme/lib@1.0.0.0-ref.json');
        $old = $this->tmp.'/store/files/'.substr(hash('sha256', 'old'), 0, 2).'/'.hash('sha256', 'old');
        touch($old, time() - 2 * 3600);

        self::assertSame(['entries' => [], 'files' => [$old]], $store->prune(time()));

        self::assertFileExists($this->tmp.'/store/files/'.substr(hash('sha256', 'fresh'), 0, 2).'/'.hash('sha256', 'fresh'), 'an orphan being ingested right now must survive');
        self::assertFileDoesNotExist($old);
    }

    public function testIndexEntryListsFilesEmptyDirectoriesAndSymlinks(): void
    {
        file_put_contents($this->tmp.'/pkg/a.txt', 'content');
        mkdir($this->tmp.'/pkg/empty');
        symlink('a.txt', $this->tmp.'/pkg/b.txt');
        symlink('bin', $this->tmp.'/pkg/bin-alias');
        $store = new Store($this->tmp.'/store');

        $store->ingest('acme/lib@1.0.0.0-ref', $this->tmp.'/pkg');

        $index = json_decode((string) file_get_contents($this->tmp.'/store/index/acme/lib@1.0.0.0-ref.json'), true);
        self::assertSame([
            'files' => ['a.txt' => ['hash' => hash('sha256', 'content'), 'mode' => 0644]],
            'dirs' => ['bin', 'empty'],
            'links' => ['b.txt' => 'a.txt', 'bin-alias' => 'bin'],
        ], $index);
    }
}
