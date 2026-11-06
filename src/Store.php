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
    private readonly \Closure $cloner;
    private ?string $fallbackReason = null;

    /**
     * @param (callable(string $source, string $dest): bool)|null       $linker hard-link operation, PHP's link() by default; injectable so tests can make it fail
     * @param (callable(list<string> $sources, string $dir): bool)|null $cloner copy-on-write clone of many files into one directory, keeping their names; one `cp` process by default
     */
    public function __construct(private readonly string $root, ?callable $linker = null, ?callable $cloner = null)
    {
        $this->linker = \Closure::fromCallable($linker ?? link(...));
        $this->cloner = \Closure::fromCallable($cloner ?? self::cloneInto(...));
    }

    public function path(): string
    {
        return $this->root;
    }

    /** Total size in bytes of the stored files (0 when the store does not exist yet). */
    public function size(): int
    {
        // ponytail: full walk of files/ on every call; cache the total in a sidecar if `status` ever feels slow on a big store.
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
     */
    public function ingest(string $key, string $dir): void
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
    }

    /** Whether $key was ingested before (its index entry exists). */
    public function has(string $key): bool
    {
        return file_exists($this->indexPath($key));
    }

    /**
     * Materialises the $key entry into $target with the given strategy (see Config::STRATEGIES).
     * Refreshes the entry's mtime first: prune() treats it as the time of last use.
     *
     * @param string[] $copy relative paths always materialised as private copies (declared bins)
     *
     * @return string the strategy actually used
     */
    public function materialise(string $key, string $target, string $strategy, array $copy = []): string
    {
        touch($this->indexPath($key));
        if ('hardlink' === $strategy && $this->canMaterialiseInto($key, $target, $strategy)) {
            $this->link($key, $target, $copy);

            return 'hardlink';
        }
        if ('reflink' === $strategy && $this->canMaterialiseInto($key, $target, $strategy)) {
            $this->reflink($key, $target, $copy);

            return 'reflink';
        }
        $this->copy($key, $target);

        return 'copy';
    }

    /** Why the first materialisation of this run fell back to copy (store on another filesystem, ...), null if none did. */
    public function fallbackReason(): ?string
    {
        return $this->fallbackReason;
    }

    /**
     * Tries the entry's first file against a probe path in $target: no upfront filesystem detection, the first link or clone tells.
     * ponytail: for reflink the probe is one `cp` process, then reflink() runs another per directory (two for the first one);
     * fold the probe into the first batch if process spawns ever show up in profiles.
     */
    private function canMaterialiseInto(string $key, string $target, string $strategy): bool
    {
        $files = $this->entry($key)['files'];
        if ([] === $files) {
            return true;
        }
        $source = $this->filePathFor($files[array_key_first($files)]);
        $this->mkdir($target);
        $probe = 'reflink' === $strategy ? $target.'/'.basename($source) : $target.'/.pcomposer-probe';
        try {
            $ok = 'reflink' === $strategy ? ($this->cloner)([$source], $target) : ($this->linker)($source, $probe);
            $reason = "$strategy operation returned false";
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
        foreach ($entry['files'] as $relative => $file) {
            $dest = $target.'/'.$relative;
            if (\in_array($relative, $copy, true)) {
                $this->copyPrivate($this->filePathFor($file), $dest, $file['mode']);
                continue;
            }
            $this->mkdir(\dirname($dest));
            if (!($this->linker)($this->filePathFor($file), $dest)) {
                throw new \RuntimeException("Cannot materialise $dest from the store");
            }
        }
        $this->recreate($target, $entry);
    }

    /**
     * Like link(), with files cloned copy-on-write: one `cp` per target directory clones the store files under their
     * hash names, then each is renamed to its package name (same directory, so a rename is free) and given normal permissions.
     *
     * @param string[] $copy relative paths materialised as private copies (declared bins)
     */
    private function reflink(string $key, string $target, array $copy): void
    {
        $entry = $this->entry($key);
        $byDir = [];
        foreach ($entry['files'] as $relative => $file) {
            $dest = $target.'/'.$relative;
            if (\in_array($relative, $copy, true)) {
                $this->copyPrivate($this->filePathFor($file), $dest, $file['mode']);
                continue;
            }
            $byDir[\dirname($dest)][$dest] = [$this->filePathFor($file), $file['mode']];
        }
        foreach ($byDir as $dir => $files) {
            $this->mkdir($dir);
            // ponytail: one argv per directory; chunk if a package ever has enough files in one directory to hit ARG_MAX.
            if (!($this->cloner)(array_values(array_unique(array_column($files, 0))), $dir)) {
                throw new \RuntimeException("Cannot clone into $dir from the store");
            }
            $placed = [];
            foreach ($files as $dest => [$source, $mode]) {
                // Identical content twice in one directory: the clone was consumed by the first rename, copy the sibling.
                $ok = isset($placed[$source]) ? copy($placed[$source], $dest) : rename($dir.'/'.basename($source), $dest);
                $placed[$source] ??= $dest;
                if (!$ok || !chmod($dest, $mode)) {
                    throw new \RuntimeException("Cannot materialise $dest from the store");
                }
            }
        }
        $this->recreate($target, $entry);
    }

    /**
     * Recreates the entry's empty directories and symlinks (with their original targets) under $target.
     *
     * @param array{dirs: list<string>, links: array<string, string>} $entry
     */
    private function recreate(string $target, array $entry): void
    {
        foreach ($entry['dirs'] as $dir) {
            $this->mkdir($target.'/'.$dir);
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

    /** Orphan files younger than this are kept: an install may be ingesting them, its index entry not written yet. */
    public const PRUNE_GRACE_PERIOD = 3600;

    /**
     * Deletes index entries last used before $olderThan (unix time), then every store file no remaining entry references
     * and older than PRUNE_GRACE_PERIOD.
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
            foreach ($this->entryAt($index)['files'] as $file) {
                $referenced[$this->filePathFor($file)] = true;
            }
        }
        // ponytail: an mtime grace period, not a lock; an ingest longer than an hour is still exposed. Upgrade: flock on the store.
        $young = time() - self::PRUNE_GRACE_PERIOD;
        $orphans = array_values(array_filter($this->storeFiles(), static fn (string $path): bool => !isset($referenced[$path]) && filemtime($path) < $young));
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

    /** @param array{hash: string, mode: int} $entry an index file entry */
    private function filePathFor(array $entry): string
    {
        return $this->filePath($entry['hash'], 0 !== ($entry['mode'] & 0100));
    }

    /** Private copy with normal permissions (declared bins, and every file of the copy strategy). */
    private function copyPrivate(string $source, string $dest, int $mode): void
    {
        $this->mkdir(\dirname($dest));
        if (!copy($source, $dest) || !chmod($dest, $mode)) {
            throw new \RuntimeException("Cannot materialise $dest from the store");
        }
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

    /**
     * Atomic temp + link: link() fails when the destination exists, so two processes ingesting the same
     * content never replace each other's inode (a rename would). An existing entry is left untouched.
     */
    private function put(string $storeFile, string $source, bool $executable): void
    {
        if (file_exists($storeFile)) {
            return;
        }
        $this->mkdir(\dirname($storeFile));
        $tmp = \dirname($storeFile).'/.tmp-'.bin2hex(random_bytes(8));
        $ok = copy($source, $tmp) && chmod($tmp, $executable ? 0555 : 0444) && (@link($tmp, $storeFile) || file_exists($storeFile));
        @unlink($tmp);
        if (!$ok) {
            throw new \RuntimeException("Cannot write $storeFile to the store");
        }
    }

    /**
     * One `cp` process cloning every source into $dir (copy-on-write: `cp -c` on macOS, `cp --reflink=always` elsewhere).
     * Throws with cp's stderr when the filesystem refuses.
     * ponytail: macOS `cp -c` silently falls back to a plain copy on non-APFS volumes, so the manifest says reflink
     * for what are copies; detect it by comparing inode block counts (`stat`) after the probe if that ever matters.
     *
     * @param list<string> $sources
     */
    private static function cloneInto(array $sources, string $dir): bool
    {
        $cp = 'Darwin' === \PHP_OS_FAMILY ? ['cp', '-c'] : ['cp', '--reflink=always'];
        $process = proc_open([...$cp, ...$sources, $dir.'/'], [2 => ['pipe', 'w']], $pipes);
        if (false === $process) {
            throw new \RuntimeException('Cannot start cp');
        }
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        if (0 !== $code = proc_close($process)) {
            throw new \RuntimeException('' !== $stderr ? $stderr : "cp exited with $code");
        }

        return true;
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
    }
}
