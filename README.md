# pcomposer

Composer plugin: pnpm-style content-addressable store with hardlink/reflink/copy materialisation into `vendor/`.

## Development

```sh
composer install
composer test   # PHPUnit (end-to-end tests run the real `composer` binary; override with COMPOSER_BINARY)
composer lint   # php-cs-fixer check
composer stan   # PHPStan, level max
```
