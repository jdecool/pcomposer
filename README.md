# pcomposer

Composer plugin: pnpm-style content-addressable store with hardlink/reflink/copy materialisation into `vendor/`.

## Development

```sh
composer install
composer test   # PHPUnit (end-to-end tests run the real `composer` binary; override with COMPOSER_BINARY)
composer lint   # php-cs-fixer check
composer stan   # PHPStan, level max
```

## Installation and `allow-plugins`

Composer only runs plugins listed in `config.allow-plugins`. Add the plugin to a project:

```sh
composer config allow-plugins.jdecool/pcomposer true
composer require --dev jdecool/pcomposer
```

Or once for every project on the machine:

```sh
composer global config allow-plugins.jdecool/pcomposer true
composer global require jdecool/pcomposer
```

Setting `allow-plugins` first avoids the interactive prompt; in non-interactive runs (CI) a missing entry makes Composer abort with `PluginBlockedException`. The plugin declares `extra.plugin-modifies-downloads`, so on the very first `composer install` of a project it is installed and activated in its own batch and every other zip package of that same run is already materialised from the store. The e2e fixtures (`tests/E2E/E2ETestCase.php`) set `allow-plugins` explicitly in the project or global `composer.json` and never rely on prompts.
