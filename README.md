# pcomposer

A Composer plugin that keeps one content-addressable store of package files per machine, pnpm-style, and links them into `vendor/` instead of writing a fresh copy for every project.

Requirements: PHP 8.1+, Composer 2, macOS or Linux.

## Why

Every `composer install` extracts the same zip archives into each project's `vendor/`. The same files (Symfony, PHPUnit, PHPStan, ...) end up duplicated once per project, and once per version even when most files did not change between versions.

pcomposer stores each distinct file once, under its SHA-256, in a store shared by every project on the machine, and materialises `vendor/` from that store with hard links (default), copy-on-write clones or plain copies. `vendor/` stays a directory of regular files: PHP, autoloaders, static analysers, IDEs and Docker bind mounts see nothing unusual. `composer install`, `update`, `require` and `remove` work as before. Anything the plugin cannot handle is left to Composer's normal path.

## Install

Composer only runs plugins listed in `config.allow-plugins`. Setting it first avoids the interactive prompt; in non-interactive runs (CI) a missing entry makes Composer abort with `PluginBlockedException`.

Once for every project on the machine:

```sh
composer global config allow-plugins.jdecool/pcomposer true
composer global require jdecool/pcomposer
```

Or per project, so that every contributor and CI job uses it:

```sh
composer config allow-plugins.jdecool/pcomposer true
composer require --dev jdecool/pcomposer
```

The plugin declares `extra.plugin-modifies-downloads`, so on the very first `composer install` of a project that requires it, Composer installs and activates the plugin in its own batch and the other zip packages of that same run are already materialised from the store.

## How it works

The plugin replaces Composer's downloader for the `zip` dist type. Downloading, Composer's own zip cache, checksum verification, `prepare` and `cleanup` are inherited unchanged; only the install step differs. For each package whose `<name>@<version>-<reference>` is not in the store yet it:

1. extracts the archive to a temporary directory next to the target (`vendor/<vendor>/.pcomposer-<random>`);
2. ingests every regular file into the store, skipping content that is already there;
3. writes an index entry for `<name>@<version>-<reference>`;
4. materialises the package into `vendor/<vendor>/<name>` with the active strategy;
5. removes the temporary directory.

A package already in the store skips the extraction and goes straight to step 4. The install log shows `- Installing acme/lib (1.0.0): Linking from store` for the first case and `Linking from store (cached)` for the second.

### Store

Location: `<composer cache-dir>/pcomposer-store` (`composer config cache-dir` prints the base; typically `~/.cache/composer` on Linux and `~/Library/Caches/composer` on macOS), overridable with `PCOMPOSER_STORE`.

```
files/<hh>/<sha256>       regular file, mode 0444
files/<hh>/<sha256>-x     executable file, mode 0555
index/<vendor>/<name>@<version>-<reference>.json
```

Files are keyed by SHA-256 plus the executable bit, so identical content shared between packages, versions or projects exists once. Each index entry lists the package's files with hash and mode, its empty directories and its symlinks (symlinks are recreated, never linked). Writes are atomic (temporary file, then hard-linked into place) and an existing file is never rewritten, so several Composer processes may write to the same store at once. An index entry's mtime is refreshed every time an install uses it; `pcomposer:prune` relies on that.

### Manifest

`vendor/composer/pcomposer.json` lists every package the plugin materialised in this project, keyed by name, with its version, dist reference and the strategy actually used:

```json
{
    "acme/lib": {
        "name": "acme/lib",
        "version": "1.2.0",
        "reference": "0123abcd",
        "strategy": "hardlink"
    }
}
```

It is written after every `install`, `update` and `remove`, with or without `--no-scripts` (that flag only skips the scripts of `composer.json`, not plugin listeners). Packages installed by Composer's normal path never appear; entries whose package is no longer installed at the recorded version are dropped. The `pcomposer:*` commands read it, so they never scan inodes.

### What is and is not handled

Handled: any package whose dist is a zip archive, whichever installer or repository (Packagist, VCS, artifact, private registries) provides it.

Left to Composer, exactly as before, and absent from the manifest:

- dists that are not zip (`tar`, `xz`, `rar`, `phar`);
- source checkouts (`--prefer-source`, `git` dists);
- `path` repositories and metapackages;
- an install whose target directory is not empty (shared-path installers that merge several packages into one directory, `create-project` in `.`);
- the throwaway `<path>_compare` install that `composer status` performs to diff a package; `composer status` keeps reporting modified packages correctly.

## Strategies

| Strategy   | `vendor/` files                                           | Permissions in `vendor/`                         | Editing a file in `vendor/`                                      |
|------------|-----------------------------------------------------------|--------------------------------------------------|------------------------------------------------------------------|
| `hardlink` | Share the store's inode (PHP `link()`). Default.          | Read-only: `0444`, `0555` for executables        | Changes the store and every project linked to the same file      |
| `reflink`  | Copy-on-write clones (`cp -c` on macOS, `cp --reflink=always` on Linux), one `cp` per target directory | Normal: `0644` / `0755` | Private to this project; disk is shared until a file is modified |
| `copy`     | Plain copies                                              | Normal: `0644` / `0755`                          | Private to this project; no disk sharing                         |

`hardlink` costs nothing beyond the store itself and needs no particular filesystem, but files in `vendor/` are the store's files. `reflink` keeps writable, private files while still sharing blocks, but needs a filesystem with clone support (APFS, btrfs, XFS) and the store on the same filesystem as `vendor/`. `copy` ingests into the store but shares nothing with it; use it for projects where something writes into `vendor/` and `reflink` is not available.

In every strategy, files declared in a package's `bin` are private copies with normal permissions, so Composer's `chmod` on them never touches the store.

### Fallback

There is no upfront filesystem detection. For each package, the first file is tried with the configured strategy; if the link or clone fails (store on another filesystem, filesystem without reflink support), the whole package is materialised with `copy`, the manifest records `copy`, and one warning is printed per run:

```
pcomposer: hardlink failed (<reason>), falling back to copy for this run
```

The install succeeds either way.

## Configuration

Keys live under `extra.pcomposer` in the root `composer.json`:

```json
{
    "extra": {
        "pcomposer": {
            "enabled": true,
            "strategy": "hardlink",
            "copy": ["acme/writes-into-itself"]
        }
    }
}
```

| Key                        | Type           | Default    | Effect                                                                                      |
|----------------------------|----------------|------------|---------------------------------------------------------------------------------------------|
| `extra.pcomposer.enabled`  | bool           | `true`     | `false` deactivates the plugin for this project: Composer installs everything as usual.     |
| `extra.pcomposer.strategy` | string         | `hardlink` | `hardlink`, `reflink` or `copy`. Any other value aborts the run with `pcomposer: invalid strategy "...", expected one of hardlink, reflink, copy`. |
| `extra.pcomposer.copy`     | list of names  | `[]`       | Packages always materialised with `copy`, whatever the strategy.                            |
| `extra.patches`            | (cweagans/composer-patches) | | Every package that is a key of `extra.patches` is copied, as if listed in `extra.pcomposer.copy`, so applying a patch never modifies the store. |
| *(package `type`)*         |                |            | Packages of type `composer-plugin` are always copied: several rewrite their own files on install (`phpstan/extension-installer`, `infection/extension-installer`). |

Environment variables override the project:

| Variable             | Effect                                                                  |
|----------------------|-------------------------------------------------------------------------|
| `PCOMPOSER_DISABLE=1`| Deactivates the plugin for this run, whatever `extra.pcomposer.enabled`. |
| `PCOMPOSER_STRATEGY` | Overrides `extra.pcomposer.strategy` (same values, same validation).     |
| `PCOMPOSER_STORE`    | Store directory, instead of `<cache-dir>/pcomposer-store`.               |

Precedence: environment variable, then `composer.json`, then the default. The `copy` list and `extra.patches` detection apply on top of whichever strategy wins. On Windows the plugin activates and does nothing.

## Commands

All four read the manifest and the store; none of them needs the network.

### `composer pcomposer:status`

Prints one row per package of the manifest, then the store location and size.

```
+-----------+---------+----------+
| Package   | Version | Strategy |
+-----------+---------+----------+
| acme/lib  | 1.2.0   | hardlink |
| acme/zed  | 2.0.0   | copy     |
+-----------+---------+----------+
Store: /home/me/.cache/composer/pcomposer-store (1234567 bytes)
```

Without a manifest it prints `No package linked from the store (no vendor/composer/pcomposer.json).`. Exit code: always 0.

### `composer pcomposer:unlink <package>`

Turns a hard-linked package into a private, writable copy: every file under the package directory is copied to a sibling temporary file and renamed over the link, so the store keeps its own inode and mode; the manifest entry becomes `copy`. Use it to debug a library in place without affecting other projects.

- `<package> is now a private copy.`, exit 0;
- package installed with `reflink` or `copy`: `<package> is installed with the copy strategy, nothing to unlink.`, exit 0;
- package not in the manifest or not installed: `<package> is not linked from the store.`, exit 1.

### `composer pcomposer:verify`

Rehashes every file of the store (the expected hash is the file name) and prints `<n> files checked, <m> corrupted`, with one `Corrupted: <path>` line per mismatch. Exit code 0 when the store is clean, 1 otherwise.

### `composer pcomposer:prune [--older-than=30d] [--dry-run]`

Deletes index entries not used within the window, then every store file no remaining entry references, except files written in the last hour (an install running at the same time may be ingesting them, its index entry not written yet). The window is `<N>d`, `<N>h` or `<N>m` (default `30d`); an invalid window prints `Invalid --older-than "..."` and exits 1.

Prints `Removed <n> index entries and <m> store files` (the paths with `-v`). With `--dry-run` nothing is deleted, every path is printed and the summary reads `Would remove ...`. Exit code 0.

Projects already installed keep working: hard links keep the inode alive after the store's entry is deleted, and clones and copies never depended on it. The next install of a pruned package simply ingests it again.

## CI

The store lives under Composer's cache directory, so a job that already caches it caches the store for free. With GitHub Actions:

```yaml
- id: composer-cache
  run: echo "dir=$(composer config cache-dir)" >> "$GITHUB_OUTPUT"
- uses: actions/cache@v4
  with:
    path: ${{ steps.composer-cache.outputs.dir }}
    key: composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}
    restore-keys: composer-${{ runner.os }}-
```

To bisect an install problem without editing the project, force a strategy or switch the plugin off for one run:

```sh
PCOMPOSER_STRATEGY=copy composer install
PCOMPOSER_DISABLE=1 composer install
```

If the second one fixes it, the problem is in pcomposer; please open an issue with the output.

## Limitations

- **Windows** is not supported: the plugin activates and registers nothing, so a shared `composer.json` does not break Windows contributors.
- **Non-zip archives, source checkouts, `path` repositories** and metapackages are installed by Composer as before (see [What is and is not handled](#what-is-and-is-not-handled)).
- **No strict dependency isolation.** pnpm's non-flat `node_modules` has no equivalent here: PHP's autoloader is global and `vendor/` keeps Composer's flat layout.
- **`hardlink` and in-place edits.** With the default strategy, a file in `vendor/` *is* the store's file. Anything that writes into an existing file in place, instead of replacing it, changes the store and therefore every project linked to the same content: patch plugins, `pre-`/`post-package-install` scripts that edit package files, a `chmod` followed by an edit. Store files are read-only (`0444` / `0555`) precisely so that an accidental edit fails rather than propagates. Mitigations, in increasing order of cost:
  - list the packages concerned in `extra.pcomposer.copy`;
  - packages that are keys of `extra.patches` (cweagans/composer-patches) and packages of type `composer-plugin` are already copied automatically;
  - `composer pcomposer:unlink <package>` for a one-off debugging session;
  - `extra.pcomposer.strategy: "reflink"` removes the problem entirely, on a filesystem that supports it;
  - `extra.pcomposer.strategy: "copy"` when it does not.

  `composer pcomposer:verify` tells you whether a store file was altered.
- **`reflink` needs APFS, btrfs or XFS** (with reflink enabled), and the store on the same filesystem as the project. On Linux, `cp --reflink=always` refuses on other filesystems and the plugin falls back to `copy` with a warning. On macOS, `cp -c` **silently copies** on non-APFS volumes: no warning, and the manifest still says `reflink` although the files are plain copies.
- **`prune` and concurrent installs.** `pcomposer:prune` deletes store files referenced by no index entry. Files written in the last hour are spared, which covers an install running at the same time on the same store; an ingest that takes longer than that is still exposed, so prefer running prune when nothing else uses the store (parallel CI jobs sharing one cache included).
- **A run with the plugin disabled leaves the manifest stale.** `PCOMPOSER_DISABLE=1` or `enabled: false` installs through Composer without touching `vendor/composer/pcomposer.json`, so its entries may describe packages that are now plain copies until the next enabled run rewrites it.
- **Every install still goes through Composer's download step** (served from its zip cache when possible); only the extraction is skipped for packages already in the store.

## Contributing

```sh
composer install
composer test   # PHPUnit (end-to-end tests run the real `composer` binary; override with COMPOSER_BINARY)
composer lint   # php-cs-fixer check
composer stan   # PHPStan, level max
```

End-to-end tests build zip packages into a local artifact repository and run Composer as a subprocess with an isolated `COMPOSER_HOME`, `COMPOSER_CACHE_DIR` and `PCOMPOSER_STORE`, then assert on inodes, permissions, the manifest and command output. The reflink test only asserts clone semantics on macOS (APFS). CI runs the three scripts on PHP 8.1 to 8.5, on Ubuntu and macOS.

## License

MIT.
