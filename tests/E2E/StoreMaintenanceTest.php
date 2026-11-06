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

    private function indexFile(string $package): string
    {
        $files = (array) glob($this->tmp.'/store/index/'.$package.'@*.json');
        self::assertCount(1, $files, "no single index entry for $package");

        return (string) $files[0];
    }
}
