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
     * @param array<string, string> $links      relative path => symlink target, as written in the archive
     * @param list<string>          $dirs       relative paths of empty directories
     *
     * @return string path of the zip
     */
    protected function addPackage(string $name, string $version, array $files = [], array $executable = [], array $extra = [], array $links = [], array $dirs = []): string
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
        foreach ($links as $path => $to) {
            $zip->addFromString($path, $to);
            $zip->setExternalAttributesName($path, \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        foreach ($dirs as $dir) {
            $zip->addEmptyDir($dir);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Zips this repository's plugin (composer.json + src/) into the fixture's artifact repository.
     * Artifact packages take their version from the zipped composer.json, so it is injected there.
     *
     * @return string path of the zip
     */
    protected function addPluginPackage(string $version): string
    {
        $root = \dirname(__DIR__, 2);
        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $zipPath = $this->tmp.'/artifacts/jdecool-pcomposer-'.$version.'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('composer.json', self::json(['version' => $version] + $json));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $zip->addFile($path, substr($path, \strlen($root) + 1));
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Builds a package served by the fixture's `path` repository (installed by Composer's path downloader).
     *
     * @param array<string, string> $files relative path => content
     *
     * @return string package dir
     */
    protected function addPathPackage(string $name, string $version, array $files = []): string
    {
        $dir = $this->tmp.'/paths/'.str_replace('/', '-', $name);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/composer.json', self::json(['name' => $name, 'version' => $version]));
        foreach ($files as $path => $content) {
            @mkdir(\dirname($dir.'/'.$path), 0777, true);
            file_put_contents($dir.'/'.$path, $content);
        }

        return $dir;
    }

    /**
     * Creates a fixture project requiring the plugin (from this repository, via a `path` repository) and the given packages.
     *
     * @param array<string, string> $require name => constraint
     * @param array<string, mixed>  $extra   extra root composer.json fields, merged over the defaults
     * @param string                $name    directory name, to create several projects in one test
     *
     * @return string fixture project dir
     */
    protected function createProject(array $require = [], array $extra = [], string $name = 'project'): string
    {
        /** @var array<string, mixed> $extra */
        $extra = array_replace_recursive([
            'repositories' => [
                ['type' => 'path', 'url' => \dirname(__DIR__, 2), 'options' => ['versions' => ['jdecool/pcomposer' => '1.0.0']]],
            ],
            'config' => ['allow-plugins' => ['jdecool/pcomposer' => true]],
        ], $extra);

        return $this->createBareProject(['jdecool/pcomposer' => '*'] + $require, $extra, $name);
    }

    /**
     * Creates a fixture project that does not mention the plugin: it only sees the artifact and `path` fixture repositories.
     *
     * @param array<string, string> $require name => constraint
     * @param array<string, mixed>  $extra   extra root composer.json fields, merged over the defaults (`repositories` are prepended)
     * @param string                $name    directory name, to create several projects in one test
     *
     * @return string fixture project dir
     */
    protected function createBareProject(array $require = [], array $extra = [], string $name = 'project'): string
    {
        $dir = $this->tmp.'/'.$name;
        mkdir($dir);
        /** @var list<array<string, mixed>> $repositories */
        $repositories = $extra['repositories'] ?? [];
        unset($extra['repositories']);
        /** @var array<string, mixed> $json */
        $json = array_replace_recursive([
            'name' => 'fixture/project',
            'require' => $require,
            'repositories' => [
                ...$repositories,
                ['type' => 'artifact', 'url' => $this->tmp.'/artifacts'],
                ['type' => 'path', 'url' => $this->tmp.'/paths/*'],
                ['packagist.org' => false],
            ],
        ], $extra);
        file_put_contents($dir.'/composer.json', self::json($json));

        return $dir;
    }

    /**
     * Installs the plugin from the artifact repository into the isolated COMPOSER_HOME (`composer global require`).
     * The global composer.json is written first: allow-plugins is checked against it, and prompts are off.
     */
    protected function installPluginGlobally(string $version): void
    {
        $home = $this->tmp.'/home';
        mkdir($home, 0777, true);
        file_put_contents($home.'/composer.json', self::json([
            'repositories' => [['type' => 'artifact', 'url' => $this->tmp.'/artifacts'], ['packagist.org' => false]],
            'config' => ['allow-plugins' => ['jdecool/pcomposer' => true]],
        ]));

        [$exitCode, $output] = $this->composer($this->tmp, ['global', 'require', 'jdecool/pcomposer:'.$version]);
        self::assertSame(0, $exitCode, $output);
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
            'PCOMPOSER_STRATEGY' => '',
            'PCOMPOSER_DISABLE' => '',
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

    /** @return array<string, array{name: string, version: string, reference: string, strategy: string}> the project's vendor/composer/pcomposer.json */
    protected function manifest(string $project): array
    {
        /** @var array<string, array{name: string, version: string, reference: string, strategy: string}> $entries */
        $entries = json_decode((string) file_get_contents($project.'/vendor/composer/pcomposer.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $entries;
    }

    /** @return list<int> inodes of every regular file under the store */
    protected function storeInodes(): array
    {
        $inodes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp.'/store', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $inodes[] = $file->getInode();
            }
        }

        return $inodes;
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
