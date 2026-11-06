<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ManifestTest extends E2ETestCase
{
    public function testInstallWritesTheManifestForLinkedPackagesOnly(): void
    {
        $this->addPackage('acme/zed', '1.0.0', ['src/Zed.php' => '<?php class Zed {}']);
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $this->addPathPackage('acme/local', '1.0.0', ['src/Local.php' => '<?php class Local {}']);
        $project = $this->createProject(['acme/zed' => '1.0.0', 'acme/lib' => '1.0.0', 'acme/local' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        $json = (string) file_get_contents($project.'/vendor/composer/pcomposer.json');
        self::assertSame([
            'acme/lib' => ['name' => 'acme/lib', 'version' => '1.0.0', 'reference' => $this->zipSha1('acme/lib', '1.0.0'), 'strategy' => 'hardlink'],
            'acme/zed' => ['name' => 'acme/zed', 'version' => '1.0.0', 'reference' => $this->zipSha1('acme/zed', '1.0.0'), 'strategy' => 'hardlink'],
        ], json_decode($json, true, 512, \JSON_THROW_ON_ERROR), 'entries keyed and sorted by name; path package absent');
        self::assertStringStartsWith("{\n    \"acme/lib\": {\n        \"name\"", $json, 'pretty-printed');
    }

    /** Artifact packages have no dist reference: Composer uses the zip's sha1 instead. */
    private function zipSha1(string $name, string $version): string
    {
        return sha1_file($this->tmp.'/artifacts/'.str_replace('/', '-', $name).'-'.$version.'.zip') ?: '';
    }
}
