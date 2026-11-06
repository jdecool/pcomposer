<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

/**
 * Content-addressable store: one read-only file per (sha256, executable bit),
 * plus one JSON index per package entry listing its files, empty directories and symlinks.
 *
 * Layout:
 *   files/<hh>/<sha256>      regular file, 0444
 *   files/<hh>/<sha256>-x    executable file, 0555
 *   index/<vendor>/<name>@<version>-<reference>.json
 *     {"files": {<path>: {"hash", "mode"}}, "dirs": [<path>], "links": {<path>: <symlink target>}}
 */
final class Store
{
    private readonly \Closure $linker;
    private ?string $fallbackReason = null;

    /** @param (callable(string $source, string $dest): bool)|null $linker hard-link operation, PHP's link() by default; injectable so tests can make it fail */
    public function __construct(private readonly string $root, ?callable $linker = null)
    {
        $this->linker = \Closure::fromCallable($linker ?? link(...));
    }

    public function path(): string
    {
        return $this->root;
    }

    /** Total size in bytes of the stored files (0 when the store does not exist yet). */
    public function size(): int
    {
        if (!is_dir($this->root.'/files')) {
            return 0;
        }
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/files', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Copies every regular file under $dir into the store and writes the index entry for $key:
     * regular files by hash, empty directories and symlinks (never descended into) by path.
     *
     * @return array<string, array{hash: string, mode: int}> relative path => entry
     */
    public function ingest(string $key, string $dir): array
    {
        $files = $links = $dirs = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $relative = substr($path, \strlen($dir) + 1);
            if ($file->isLink()) {
                $links[$relative] = (string) readlink($path);
            } elseif ($file->isDir()) {
                if (0 === iterator_count(new \FilesystemIterator($path))) {
                    $dirs[] = $relative;
                }
            } elseif ($file->isFile()) {
                $executable = $file->isExecutable();
                $hash = hash_file('sha256', $path);
                if (false === $hash) {
                    throw new \RuntimeException("Cannot hash $path");
                }
                $this->put($this->filePath($hash, $executable), $path, $executable);
                $files[$relative] = ['hash' => $hash, 'mode' => $executable ? 0755 : 0644];
            }
        }
        ksort($files);
        ksort($links);
        sort($dirs);

        $index = $this->indexPath($key);
        $this->mkdir(\dirname($index));
        $tmp = $index.'.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode(['files' => $files, 'dirs' => $dirs, 'links' => $links], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        rename($tmp, $index);

        return $files;
    }

    /**
     * Materialises the $key entry into $target with the given strategy (see Config::STRATEGIES).
     *
     * @param string[] $copy relative paths always materialised as private copies (declared bins)
     *
     * @return string the strategy actually used
     */
    public function materialise(string $key, string $target, string $strategy, array $copy = []): string
    {
        // ponytail: reflink is ticket 06; until then it behaves as copy and is recorded as such.
        if ('hardlink' === $strategy && $this->canLinkInto($key, $target)) {
            $this->link($key, $target, $copy);

            return 'hardlink';
        }
        $this->copy($key, $target);

        return 'copy';
    }

    /** Why the first materialisation of this run fell back to copy (store on another filesystem, ...), null if none did. */
    public function fallbackReason(): ?string
    {
        return $this->fallbackReason;
    }

    /** Tries the entry's first file against a probe path in $target: no upfront filesystem detection, the first link tells. */
    private function canLinkInto(string $key, string $target): bool
    {
        $files = $this->entry($key)['files'];
        if ([] === $files) {
            return true;
        }
        ['hash' => $hash, 'mode' => $mode] = $files[array_key_first($files)];
        $this->mkdir($target);
        $probe = $target.'/.pcomposer-probe';
        try {
            $ok = ($this->linker)($this->filePath($hash, 0 !== ($mode & 0100)), $probe);
            $reason = 'link() returned false';
        } catch (\Throwable $e) {
            $ok = false;
            $reason = $e->getMessage();
        }
        @unlink($probe);
        if (!$ok) {
            $this->fallbackReason ??= $reason;
        }

        return $ok;
    }

    /**
     * Materialises the $key entry into $target (created if missing): files hard-linked,
     * empty directories created, symlinks recreated with their original target.
     *
     * @param string[] $copy relative paths materialised as private copies with normal permissions (declared bins)
     */
    public function link(string $key, string $target, array $copy = []): void
    {
        $entry = $this->entry($key);
        foreach ($entry['dirs'] as $dir) {
            $this->mkdir($target.'/'.$dir);
        }
        foreach ($entry['files'] as $relative => ['hash' => $hash, 'mode' => $mode]) {
            $dest = $target.'/'.$relative;
            $this->mkdir(\dirname($dest));
            $source = $this->filePath($hash, 0 !== ($mode & 0100));
            $ok = \in_array($relative, $copy, true) ? copy($source, $dest) && chmod($dest, $mode) : ($this->linker)($source, $dest);
            if (!$ok) {
                throw new \RuntimeException("Cannot materialise $dest from the store");
            }
        }
        foreach ($entry['links'] as $relative => $to) {
            $dest = $target.'/'.$relative;
            $this->mkdir(\dirname($dest));
            if (!symlink($to, $dest)) {
                throw new \RuntimeException("Cannot create symlink $dest");
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

    /**
     * Deletes index entries last used before $olderThan (unix time), then every store file no remaining entry references.
     *
     * @return array{entries: list<string>, files: list<string>} what was (or, with $dryRun, would be) deleted
     */
    public function prune(int $olderThan, bool $dryRun = false): array
    {
        $stale = [];
        $referenced = [];
        foreach (glob($this->root.'/index/*/*.json') ?: [] as $index) {
            if (filemtime($index) < $olderThan) {
                $stale[] = $index;
                continue;
            }
            foreach ($this->entryAt($index)['files'] as ['hash' => $hash, 'mode' => $mode]) {
                $referenced[$this->filePath($hash, 0 !== ($mode & 0100))] = true;
            }
        }
        $orphans = array_values(array_filter($this->storeFiles(), static fn (string $path): bool => !isset($referenced[$path])));
        if (!$dryRun) {
            foreach ([...$stale, ...$orphans] as $path) {
                if (!unlink($path)) {
                    throw new \RuntimeException("Cannot delete $path");
                }
            }
        }

        return ['entries' => $stale, 'files' => $orphans];
    }

    /** @return list<string> absolute paths of every file under files/ */
    private function storeFiles(): array
    {
        $paths = glob($this->root.'/files/*/*') ?: [];
        sort($paths);

        return $paths;
    }

    /**
     * Copies every file of the $key entry into $target (created if missing) with normal permissions (0644 / 0755).
     */
    public function copy(string $key, string $target): void
    {
        $this->link($key, $target, array_keys($this->entry($key)['files']));
    }

    /** @return array{files: array<string, array{hash: string, mode: int}>, dirs: list<string>, links: array<string, string>} */
    private function entry(string $key): array
    {
        return $this->entryAt($this->indexPath($key));
    }

    /** @return array{files: array<string, array{hash: string, mode: int}>, dirs: list<string>, links: array<string, string>} */
    private function entryAt(string $index): array
    {
        $json = file_get_contents($index);
        if (false === $json) {
            throw new \RuntimeException("No store entry at $index");
        }
        /** @var array{files: array<string, array{hash: string, mode: int}>, dirs?: list<string>, links?: array<string, string>} $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $data + ['dirs' => [], 'links' => []];
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
