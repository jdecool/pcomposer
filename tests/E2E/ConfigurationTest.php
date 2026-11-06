<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class ConfigurationTest extends E2ETestCase
{
    public function testDisabledInComposerJsonInstallsThroughComposerAndWritesNoManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['enabled' => false]]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertSame(0, $exitCode, $output);
        self::assertTrue(is_writable($project.'/vendor/acme/lib/src/Foo.php'), 'extracted by Composer, not linked');
        self::assertDirectoryDoesNotExist($this->tmp.'/store');
        self::assertFileDoesNotExist($project.'/vendor/composer/pcomposer.json');
    }

    public function testDisabledByEnvironmentInstallsThroughComposerAndWritesNoManifest(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0']);

        [$exitCode, $output] = $this->composer($project, ['install'], ['PCOMPOSER_DISABLE' => '1']);

        self::assertSame(0, $exitCode, $output);
        self::assertTrue(is_writable($project.'/vendor/acme/lib/src/Foo.php'), 'extracted by Composer, not linked');
        self::assertDirectoryDoesNotExist($this->tmp.'/store');
        self::assertFileDoesNotExist($project.'/vendor/composer/pcomposer.json');
    }

    public function testAnUnknownStrategyFailsTheInstallWithAClearMessage(): void
    {
        $this->addPackage('acme/lib', '1.0.0', ['src/Foo.php' => '<?php class Foo {}']);
        $project = $this->createProject(['acme/lib' => '1.0.0'], ['extra' => ['pcomposer' => ['strategy' => 'symlink']]]);

        [$exitCode, $output] = $this->composer($project, ['install']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('pcomposer: invalid strategy "symlink", expected one of hardlink, reflink, copy', $output);
        self::assertFileDoesNotExist($project.'/vendor/acme/lib/src/Foo.php');
    }
}
