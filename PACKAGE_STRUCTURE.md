# Lettr Laravel Package Structure

This document describes the internals of the `lettr/lettr-laravel` package, for people working **on** the package. If you are working **with** it, read the [README](README.md) and [docs.lettr.com/quickstart/laravel](https://docs.lettr.com/quickstart/laravel/introduction) instead.

## Package Overview

This is a Laravel integration for the [`lettr/lettr-php`](https://github.com/lettr-com/lettr-php) SDK. It provides:

- A Laravel Mail driver (`MAIL_MAILER=lettr`), so Mailables and `Mail::to()` send through Lettr
- A `Lettr` facade that exposes the SDK's services, resolved lazily from the container
- `Mail::lettr()` for sending a Lettr template inline, without writing a Mailable
- `LettrMailable` / `InlineLettrMailable` base classes for template-backed mail
- Artisan commands for syncing templates and generating typed Blade, DTO and enum artifacts
- A config file and service provider handling API key resolution and auto-discovery

The SDK does the HTTP work. This package owns the Laravel-shaped surface around it, and deliberately keeps a thin pass-through where Laravel adds nothing — `Lettr::audience()` and `Lettr::campaigns()`, for example, return the SDK's own services.

## Directory Structure

```
lettr-laravel/
├── config/
│   └── lettr.php                          # Published config: API key + template paths
├── src/
│   ├── Concerns/
│   │   ├── DisplayHelper.php              # Shared console output formatting
│   │   └── ThrottlesApiRequests.php       # Retry-After aware rate-limit retry for commands
│   ├── Console/
│   │   ├── Enums/
│   │   │   └── Theme.php                  # Console colour theme
│   │   ├── CheckCommand.php               # lettr:check
│   │   ├── GenerateDtosCommand.php        # lettr:generate-dtos
│   │   ├── GenerateEnumCommand.php        # lettr:generate-enum
│   │   ├── InitCommand.php                # lettr:init
│   │   ├── PullCommand.php                # lettr:pull
│   │   └── PushCommand.php                # lettr:push
│   ├── Exceptions/
│   │   └── ApiKeyIsMissing.php            # Thrown when no API key is configured
│   ├── Facades/
│   │   └── Lettr.php                      # Facade over LettrManager
│   ├── Mail/
│   │   ├── InlineLettrMailable.php        # Mailable built by Mail::lettr()
│   │   ├── LettrMailable.php              # Base class for template-backed Mailables
│   │   └── LettrPendingMail.php           # Fluent chain behind Mail::lettr()
│   ├── Services/
│   │   └── TemplateServiceWrapper.php     # Laravel-flavoured wrapper over the SDK templates service
│   ├── Support/
│   │   ├── BladeToSparkpostConverter.php  # Blade -> SparkPost syntax (push)
│   │   ├── DtoGenerator.php               # Merge tags -> typed DTO classes
│   │   ├── MailerMixin.php                # Registers the Mail::lettr() macro
│   │   └── SparkpostToBladeConverter.php  # SparkPost -> Blade syntax (pull)
│   ├── Transport/
│   │   └── LettrTransportFactory.php      # Symfony Mailer transport
│   ├── LettrManager.php                   # Lazily resolves the SDK and exposes its services
│   └── LettrServiceProvider.php           # Registration, publishing, mail driver, VERSION
├── stubs/                                 # Templates for generated Mailables, DTOs and enums
├── tests/
│   ├── Feature/                           # Console commands + README example tests
│   ├── Unit/                              # Manager, transport, mail, converters, SDK surface
│   ├── Pest.php
│   └── TestCase.php
├── .github/workflows/                     # ci.yml (PR checks), release.yml (tag -> release)
├── composer.json
├── phpstan.neon                           # Larastan, level 8
├── phpunit.xml
├── pint.json
└── README.md
```

## Key Components

### Service Provider (`src/LettrServiceProvider.php`)

- Holds `VERSION`, which is sent as the `lettr-laravel/<version>` User-Agent suffix — bump it on every release
- Registers `LettrManager` as the `lettr` singleton, resolving the API key from `config('lettr.api_key')` with `config('services.lettr.key')` as a fallback, and throwing `ApiKeyIsMissing` when neither is set
- Extends Laravel's Mail system with the `lettr` transport
- Registers the `Mail::lettr()` macro and the six Artisan commands
- Publishes `config/lettr.php`

### Manager (`src/LettrManager.php`)

Resolves the SDK lazily — nothing hits the network until a service is actually used. Exposes `emails()`, `domains()`, `projects()`, `webhooks()`, `health()`, `audience()`, `campaigns()` and `templates()`, each also reachable as a magic property (`app('lettr')->audience`). All but `templates()` return the SDK's own service; `sdk()` returns the underlying client for anything not wrapped.

### Mail Transport (`src/Transport/LettrTransportFactory.php`)

Implements Symfony Mailer's `AbstractTransport`. Converts a Symfony `Email` into the SDK's `SendEmailData`, carrying template slug/version and substitution data across via `X-Lettr-*` headers, and rethrows SDK failures as `TransportException`.

### Console Commands (`src/Console/`)

`lettr:init` scaffolds config and directories, `lettr:check` verifies the integration end to end, `lettr:pull` / `lettr:push` sync templates (converting between Blade and SparkPost syntax), and `lettr:generate-enum` / `lettr:generate-dtos` produce typed artifacts from template merge tags.

## Development

```bash
composer install
composer test      # Pest
composer lint      # Pint (append -- --test to check without fixing)
composer analyse   # PHPStan via Larastan, level 8
```

CI runs all three across the supported PHP and Laravel matrix on every PR. See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow and [VERSIONING.md](VERSIONING.md) for the release process.

### Test Layout

| File | Covers |
| :--- | :--- |
| `Unit/LettrServiceProviderTest.php` | Registration, API key resolution, User-Agent |
| `Unit/LettrTransportTest.php` | Symfony `Email` -> `SendEmailData` conversion |
| `Unit/LettrPendingMailTest.php` | The `Mail::lettr()` chain |
| `Unit/AudienceServiceTest.php` | Audience service resolution + bulk contact surface |
| `Unit/CampaignServiceTest.php` | Campaign service resolution |
| `Unit/SdkSurfaceTest.php` | SDK builders, filters, DTOs and enums exposed via the facade |
| `Unit/DtoGeneratorTest.php`, `Unit/*ConverterTest.php` | Code generation and Blade/SparkPost conversion |
| `Feature/*CommandTest.php` | Each Artisan command |
| `Feature/ReadmeDocTest.php` | Every code example currently in the README |

`ReadmeDocTest.php` is generated by the readme-doc-test skill and must track the README exactly — if an example is removed from the README, its test moves to `SdkSurfaceTest.php` rather than staying behind.

## Dependencies

### Runtime

| Package | Constraint |
| :--- | :--- |
| `php` | `^8.4` |
| `lettr/lettr-php` | `^2.5.0` |
| `illuminate/http`, `illuminate/support` | `^10.0 \| ^11.0 \| ^12.0 \| ^13.0` |
| `symfony/mailer` | `^6.2 \| ^7.0 \| ^8.0` |

### Development

`laravel/pint ^1.18` · `larastan/larastan ^2.0|^3.0` · `mockery/mockery ^1.5` · `orchestra/testbench ^8.17|^9.0|^10.8|^11.0` · `pestphp/pest ^2.0|^3.7`

Constraints drift — `composer.json` is the source of truth if this table disagrees with it.

## Notes

- Auto-discovery registers the provider and the `Lettr` facade alias via the `extra.laravel` block in `composer.json`
- `composer.lock` is intentionally gitignored; this is a library, so CI resolves dependencies fresh
- `.ide.php` exists only to give IDEs the `Mail::lettr()` macro signature and is never loaded at runtime
