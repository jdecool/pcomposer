<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Drives the real `composer` binary against a throwaway fixture project.
 *
 * Every test gets its own temp dir holding the artifact repository, the
 * fixture project, and isolated COMPOSER_HOME / COMPOSER_CACHE_DIR / PCOMPOSER_STORE.
 */
abstract class E2ETestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pcomposer-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/artifacts', 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->tmp);
    }

    /**
     * Builds a zip package into the fixture's artifact repository.
     *
     * @param array<string, string> $files      relative path => content
     * @param list<string>          $executable relative paths that get the executable bit
     * @param array<string, mixed>  $extra      extra composer.json fields (bin, autoload, ...)
     *
     * @return string path of the zip
     */
    protected function addPackage(string $name, string $version, array $files = [], array $executable = [], array $extra = []): string
    {
        $zipPath = $this->tmp.'/artifacts/'.str_replace('/', '-', $name).'-'.$version.'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('composer.json', self::json(['name' => $name, 'version' => $version] + $extra));
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
            if (\in_array($path, $executable, true)) {
                $zip->setExternalAttributesName($path, \ZipArchive::OPSYS_UNIX, 0100755 << 16);
            }
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Creates a fixture project requiring the plugin (from this repository) and the given packages.
     *
     * @param array<string, string> $require name => constraint
     * @param array<string, mixed>  $extra   extra root composer.json fields, merged over the defaults
     *
     * @return string fixture project dir
     */
    protected function createProject(array $require = [], array $extra = []): string
    {
        $dir = $this->tmp.'/project';
        mkdir($dir);
        /** @var array<string, mixed> $json */
        $json = array_replace_recursive([
            'name' => 'fixture/project',
            'require' => ['jdecool/pcomposer' => '*'] + $require,
            'repositories' => [
                ['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['versions' => ['jdecool/pcomposer' => '1.0.0']]],
                ['type' => 'artifact', 'url' => $this->tmp.'/artifacts'],
                ['packagist.org' => false],
            ],
            'config' => ['allow-plugins' => ['jdecool/pcomposer' => true]],
        ], $extra);
        file_put_contents($dir.'/composer.json', self::json($json));

        return $dir;
    }

    /**
     * Runs `composer <args>` in the project with an isolated home, cache dir and store.
     *
     * @param list<string>          $args
     * @param array<string, string> $env  extra environment variables
     *
     * @return array{0: int, 1: string} exit code and combined stdout+stderr
     */
    protected function composer(string $projectDir, array $args, array $env = []): array
    {
        $env += [
            'COMPOSER_HOME' => $this->tmp.'/home',
            'COMPOSER_CACHE_DIR' => $this->tmp.'/cache',
            'PCOMPOSER_STORE' => $this->tmp.'/store',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ];
        $log = $this->tmp.'/composer.log';
        file_put_contents($log, '');
        $process = proc_open(
            [self::composerBinary(), ...$args],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $projectDir,
            $env + getenv(),
        );
        self::assertIsResource($process);

        return [proc_close($process), (string) file_get_contents($log)];
    }

    private static function composerBinary(): string
    {
        $binary = getenv('COMPOSER_BINARY') ?: trim((string) shell_exec('which composer'));
        self::assertNotSame('', $binary, 'composer binary not found: set COMPOSER_BINARY');

        return $binary;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private static function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $file->isDir() && !$file->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
