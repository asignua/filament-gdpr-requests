# Filament GDPR Requests

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-gdpr-requests/tests.yml?branch=main&label=tests)](https://github.com/asignua/filament-gdpr-requests/actions/workflows/tests.yml)

TODO: one-paragraph pitch of what GDPR Requests does and the problem it solves.

## Screenshots

TODO: add images to `art/` (cover.jpg first) and reference them here.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5

## Installation

```bash
composer require asignua/filament-gdpr-requests
```

Register the plugin on your panel:

```php
use Asignua\FilamentGdprRequests\GdprRequestsPlugin;

$panel->plugin(GdprRequestsPlugin::make());
```

## Usage

TODO

## Configuration

TODO: fluent setters on `GdprRequestsPlugin`, or the published config (`php artisan vendor:publish --tag=filament-gdpr-requests-config`).

## Gotchas

TODO: the traps that cost time, each with the symptom and the fix.

## Translations

The interface ships in English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish
under the `filament-gdpr-requests::filament-gdpr-requests` namespace. A test keeps every language in step with the English keys. Override a
string by publishing the translations (`--tag=filament-gdpr-requests-translations`) and editing the copy in
`lang/vendor/filament-gdpr-requests`.

## AI agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines (`resources/boost/guidelines/core.blade.php`) so a
coding agent wires it up correctly.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
