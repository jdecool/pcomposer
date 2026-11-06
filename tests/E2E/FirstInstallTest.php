<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

/**
 * The plugin is itself a zip in the artifact repository: Composer must install and
 * activate it in its own batch (`extra.plugin-modifies-downloads`) so the other
 * packages of the same first run are materialised from the store.
 */
final class FirstInstallTest extends E2ETestCase
{
    public function testFirstInstallWithThePluginInRequireDevLinksTheOtherPackages(): void
    {
        $this->addPluginPackage('1.0.0');
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createBareProject(['acme/lib' => '1.0.0'], [
            'require-dev' => ['jdecool/pcomposer' => '1.0.0'],
            'config' => ['allow-plugins' => ['jdecool/pcomposer' => true]],
        ]);

        [$exitCode, $output] = $this->composer($project, ['install', '-vvv']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Loading plugin JDecool\Composer\Plugin\PComposer\Plugin (from jdecool/pcomposer)', $output);
        self::assertContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertFileExists($project.'/vendor/jdecool/pcomposer/src/Plugin.php');
        self::assertSame(['acme/lib'], array_keys($this->manifest($project)), 'the plugin itself is installed by Composer before activation');
    }

    public function testProjectThatDoesNotMentionTheGloballyInstalledPluginGetsLinkedPackages(): void
    {
        $this->addPluginPackage('1.0.0');
        $this->installPluginGlobally('1.0.0');
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createBareProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install', '-vvv']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Loading plugin JDecool\Composer\Plugin\PComposer\Plugin (from jdecool/pcomposer, installed globally)', $output);
        self::assertContains(fileinode($project.'/vendor/acme/lib/src/Foo.php'), $this->storeInodes());
        self::assertFileDoesNotExist($project.'/vendor/jdecool');
        self::assertSame(['acme/lib'], array_keys($this->manifest($project)));
    }
}
