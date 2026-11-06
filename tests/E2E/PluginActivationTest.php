<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class PluginActivationTest extends E2ETestCase
{
    public function testPluginIsLoadedDuringInstallAndInstallSucceeds(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install', '-vvv']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Loading plugin JDecool\Composer\Plugin\PComposer\Plugin (from jdecool/pcomposer)', $output);
        self::assertFileExists($project.'/vendor/acme/lib/src/Foo.php');
    }
}
