# pcomposer

Composer plugin (`jdecool/pcomposer`): pnpm-style content-addressable store. Zip dists are extracted once into a global store, then materialised into `vendor/` by hardlink (default), reflink or copy. User-facing behaviour, configuration and limitations: `README.md`.

## Commands

```sh
composer test   # PHPUnit; E2E tests run the real `composer` binary (override with COMPOSER_BINARY)
composer lint   # php-cs-fixer check (fix: vendor/bin/php-cs-fixer fix)
composer stan   # PHPStan, level max, on src/ and tests/
```

CI runs all three on PHP 8.1–8.5 × ubuntu/macos. Code must stay PHP 8.1 compatible.

## Layout

- `src/Plugin.php`: activation; swaps the `zip` downloader, writes the manifest, registers commands. Does nothing on Windows.
- `src/StoreZipDownloader.php`: overrides only `install()` of Composer's zip downloader (extract to temp dir → ingest into store → materialise). Download, cache, checksum are inherited.
- `src/Store.php`: SHA-256 per file (key includes the executable bit), atomic writes, one index entry per `name@version-reference`.
- `src/Config.php`: `extra.pcomposer.*` and `PCOMPOSER_*` env vars, packages forced to copy.
- `src/Manifest.php`: `vendor/composer/pcomposer.json`, the only thing CLI commands read.
- `src/Command/`: `pcomposer:status|unlink|verify|prune`.

## Invariants

- Never break an install: anything unhandled (non-zip, source, `path`, non-empty target dir) falls back to Composer's normal behaviour; a failed link falls back to copy with one warning per run.
- Store files are read-only (`0444`/`0555`) and never rewritten once present. Anything that writes into a package dir after install must be copied, not hardlinked: declared `bin` files, `extra.pcomposer.copy`, keys of `extra.patches`, packages of type `composer-plugin`.
- Symlinks and empty dirs in archives are recreated, never linked.

## Tests

- Prefer E2E (`tests/E2E/`, extend `E2ETestCase`): build zips with `addPackage()`, run real Composer in an isolated temp project, assert on what a user observes (inodes, permissions, manifest, command output). No network.
- Unit tests (`tests/Unit/`) only for what E2E cannot drive portably (cross-filesystem link failure, reflink failure, concurrency).
- The reflink test only asserts clone semantics on macOS (APFS).

## Conventions

- Everything in English: code, CLI messages, docs, commits.
- Conventional commits (`fix:`, `docs:`, `refactor:`, `test:`).
- Classes are `final`. Update `README.md` when user-facing behaviour changes.
