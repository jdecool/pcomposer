<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class StoreMaintenanceTest extends E2ETestCase
{
    public function testAnInstallTouchesTheIndexEntriesItLinksFrom(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $a = $this->createProject(['acme/lib' => '1.0.0'], [], 'a');
        $b = $this->createProject(['acme/lib' => '1.0.0'], [], 'b');
        [$exitCode, $output] = $this->composer($a, ['install']);
        self::assertSame(0, $exitCode, $output);
        $index = $this->indexFile('acme/lib');
        touch($index, time() - 3600);
        clearstatcache();

        [$exitCode, $output] = $this->composer($b, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertGreaterThan(time() - 60, filemtime($index), 'index entry was not touched by the second install');
    }

    public function testVerifyReportsACorruptedStoreFileAndFailsOtherwisePasses(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}', 'src/Bar.php' => '<?php class Bar {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['pcomposer:verify']);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('3 files', $output);

        $hash = hash('sha256', '<?php class Bar {}');
        $corrupted = $this->tmp.'/store/files/'.substr($hash, 0, 2).'/'.$hash;
        chmod($corrupted, 0644);
        file_put_contents($corrupted, '<?php class Bar { public $evil; }');
        chmod($corrupted, 0444);

        [$exitCode, $output] = $this->composer($project, ['pcomposer:verify']);
        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($corrupted, $output);
        self::assertStringNotContainsString(hash('sha256', '<?php class Foo {}'), $output);
    }

    public function testPruneDropsStaleEntriesAndOrphanFilesWhileInstalledProjectsKeepWorking(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Bar.php' => '<?php namespace Foo; class Bar {}'], [], [
            'autoload' => ['psr-4' => ['Foo\\' => 'src/']],
        ]);
        $project = $this->createProject(['acme/lib' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        $index = $this->indexFile('acme/lib');
        $filesBefore = \count($this->storeInodes());
        touch($index, time() - 40 * 86400);
        $this->ageStoreFiles();

        [$exitCode, $output] = $this->composer($project, ['pcomposer:prune', '--dry-run']);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString($index, $output);
        self::assertFileExists($index);
        self::assertCount($filesBefore, $this->storeInodes());

        [$exitCode, $output] = $this->composer($project, ['pcomposer:prune']);
        self::assertSame(0, $exitCode, $output);
        self::assertFileDoesNotExist($index);
        self::assertSame([], $this->storeInodes(), 'orphan store files survived the prune');

        exec(\sprintf('cd %s && php -r %s 2>&1', escapeshellarg($project), escapeshellarg('require "vendor/autoload.php"; new Foo\Bar; echo "ok";')), $lines, $code);
        self::assertSame(['ok'], $lines, implode("\n", $lines));

        $fresh = $this->createProject(['acme/lib' => '1.0.0'], [], 'fresh');
        [$exitCode, $output] = $this->composer($fresh, ['install']);
        self::assertSame(0, $exitCode, $output);
        self::assertFileExists($index);
        self::assertCount($filesBefore, $this->storeInodes());
        self::assertContains(fileinode($fresh.'/vendor/acme/lib/src/Bar.php'), $this->storeInodes());
    }

    public function testPruneHonoursTheWindowAndKeepsFilesStillReferenced(): void
    {
        $this->addPackage('acme/old', '1.0.0', ['shared.txt' => 'shared', 'old.txt' => 'old']);
        $this->addPackage('acme/new', '1.0.0', ['shared.txt' => 'shared', 'new.txt' => 'new']);
        $project = $this->createProject(['acme/old' => '1.0.0', 'acme/new' => '1.0.0']);
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);
        touch($this->indexFile('acme/old'), time() - 2 * 3600);
        $this->ageStoreFiles();

        [$exitCode, $output] = $this->composer($project, ['pcomposer:prune', '--older-than=1y']);
        self::assertNotSame(0, $exitCode, 'unknown duration unit must be rejected');

        [$exitCode, $output] = $this->composer($project, ['pcomposer:prune', '--older-than=1h']);
        self::assertSame(0, $exitCode, $output);
        self::assertCount(0, (array) glob($this->tmp.'/store/index/acme/old@*.json'));
        self::assertFileExists($this->indexFile('acme/new'));
        self::assertFileExists($this->storeFile('shared'));
        self::assertFileExists($this->storeFile('new'));
        self::assertFileDoesNotExist($this->storeFile('old'));
    }

    /** Backdates every store file past prune's grace period, as in a store that was not written to recently. */
    private function ageStoreFiles(): void
    {
        foreach ((array) glob($this->tmp.'/store/files/*/*') as $file) {
            touch((string) $file, time() - 2 * 3600);
        }
    }

    private function storeFile(string $content): string
    {
        $hash = hash('sha256', $content);

        return $this->tmp.'/store/files/'.substr($hash, 0, 2).'/'.$hash;
    }

    private function indexFile(string $package): string
    {
        $files = (array) glob($this->tmp.'/store/index/'.$package.'@*.json');
        self::assertCount(1, $files, "no single index entry for $package");

        return (string) $files[0];
    }
}
