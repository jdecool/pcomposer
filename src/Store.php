<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

/**
 * Content-addressable store: one read-only file per (sha256, executable bit),
 * plus one JSON index per package entry listing its files.
 *
 * Layout:
 *   files/<hh>/<sha256>      regular file, 0444
 *   files/<hh>/<sha256>-x    executable file, 0555
 *   index/<vendor>/<name>@<version>-<reference>.json
 */
final class Store
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Copies every regular file under $dir into the store and writes the index entry for $key.
     *
     * @return array<string, array{hash: string, mode: int}> relative path => entry
     */
    public function ingest(string $key, string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue; // ponytail: symlinks and empty dirs are ticket 07
            }
            $path = $file->getPathname();
            $executable = $file->isExecutable();
            $hash = hash_file('sha256', $path);
            if (false === $hash) {
                throw new \RuntimeException("Cannot hash $path");
            }
            $this->put($this->filePath($hash, $executable), $path, $executable);
            $relative = substr($path, \strlen($dir) + 1);
            $files[$relative] = ['hash' => $hash, 'mode' => $executable ? 0755 : 0644];
        }
        ksort($files);

        $index = $this->indexPath($key);
        $this->mkdir(\dirname($index));
        $tmp = $index.'.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode(['files' => $files], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        rename($tmp, $index);

        return $files;
    }

    /**
     * Hard-links every file of the $key entry into $target (created if missing).
     *
     * @param list<string> $copy relative paths materialised as private copies with normal permissions (declared bins)
     */
    public function link(string $key, string $target, array $copy = []): void
    {
        foreach ($this->entry($key) as $relative => ['hash' => $hash, 'mode' => $mode]) {
            $dest = $target.'/'.$relative;
            $this->mkdir(\dirname($dest));
            $source = $this->filePath($hash, 0 !== ($mode & 0100));
            $ok = \in_array($relative, $copy, true) ? copy($source, $dest) && chmod($dest, $mode) : link($source, $dest);
            if (!$ok) {
                throw new \RuntimeException("Cannot materialise $dest from the store");
            }
        }
    }

    /**
     * Rehashes every store file; the expected hash is its file name.
     *
     * @return array{checked: int, corrupted: list<string>}
     */
    public function verify(): array
    {
        $checked = 0;
        $corrupted = [];
        foreach ($this->storeFiles() as $path) {
            ++$checked;
            if (hash_file('sha256', $path) !== explode('-', basename($path))[0]) {
                $corrupted[] = $path;
            }
        }

        return ['checked' => $checked, 'corrupted' => $corrupted];
    }

    /** @return list<string> absolute paths of every file under files/ */
    private function storeFiles(): array
    {
        $paths = glob($this->root.'/files/*/*') ?: [];
        sort($paths);

        return array_values($paths);
    }

    /** @return array<string, array{hash: string, mode: int}> */
    private function entry(string $key): array
    {
        $json = file_get_contents($this->indexPath($key));
        if (false === $json) {
            throw new \RuntimeException("No store entry for $key");
        }
        /** @var array{files: array<string, array{hash: string, mode: int}>} $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $data['files'];
    }

    private function filePath(string $hash, bool $executable): string
    {
        return $this->root.'/files/'.substr($hash, 0, 2).'/'.$hash.($executable ? '-x' : '');
    }

    /** `vendor/rest` => index/<vendor>/<rest>.json; version and reference come from package metadata, so only safe characters survive. */
    private function indexPath(string $key): string
    {
        if (str_contains($key, '..')) {
            throw new \InvalidArgumentException("Invalid store key $key");
        }
        [$vendor, $rest] = explode('/', $key, 2) + [1 => ''];
        $safe = static fn (string $s): string => (string) preg_replace('/[^A-Za-z0-9_.@-]/', '_', $s);

        return $this->root.'/index/'.$safe($vendor).'/'.$safe($rest).'.json';
    }

    /** Atomic temp + rename; an existing entry is left untouched. */
    private function put(string $storeFile, string $source, bool $executable): void
    {
        if (file_exists($storeFile)) {
            return;
        }
        $this->mkdir(\dirname($storeFile));
        $tmp = \dirname($storeFile).'/.tmp-'.bin2hex(random_bytes(8));
        if (!copy($source, $tmp) || !chmod($tmp, $executable ? 0555 : 0444) || !rename($tmp, $storeFile)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $storeFile to the store");
        }
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
    }
}
