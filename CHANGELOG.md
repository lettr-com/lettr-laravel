# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Autoload warning** — `lettr:generate-enum`, `lettr:generate-dtos` and `lettr:pull` warn when a generated class won't load because its configured namespace doesn't match the path under the app's PSR-4 mapping.
- **Overwrite warning** — `lettr:pull`, `lettr:generate-enum` and `lettr:generate-dtos` still rewrite every file they generate, but now mark files that already existed with ↻ in the summary and end with a warning that local changes to them are gone. `--dry-run` reports what would be overwritten.

### Changed

- **API-template Mailables no longer set a subject.** `lettr:pull --with-mailables --as-html` (and `--skip-templates`) used to generate `subject: Str::headline($template->name)`, which replaced the subject configured on the template in Lettr. The template's own subject is now used; set one in `envelope()` to override it. Blade Mailables keep their generated subject.
- **Loops in pulled Blade views are null-safe and use array access.** `{{#each items}}{{this.name}}` now converts to `@foreach($items ?? [] as $item){{ $item['name'] }}` instead of `@foreach($items as $item){{ $item->name }}`, matching the arrays generated DTOs pass to the view and rendering nothing when an optional loop is left out. `lettr:push` converts both back.
- **`lettr:push` looks in `lettr.templates.blade_path` first** when `--path` isn't given, so it finds what `lettr:pull` wrote. A relative `--path` is resolved against the project root.
- **camelCase merge tag keys keep their casing in DTOs** — `orderId` becomes `$orderId` instead of `$orderid`. All-uppercase keys (`FIRST_NAME` → `$firstName`) are unchanged. Re-generated DTOs may rename such constructor parameters.

### Fixed

- `lettr:pull`, `lettr:generate-enum` and `lettr:generate-dtos` read every page of templates instead of only the first 100.
- Slugs starting with a digit (`2fa-code`) no longer generate invalid PHP: classes and enum cases get a `Template` prefix (`Template2faCode`, `Template2faCodeData`).
- Slugs that are PHP reserved words no longer generate invalid PHP: classes get a `Template` suffix (`NewTemplate`), and the enum case for `class` becomes `ClassTemplate`.
- A template name containing an apostrophe no longer produces a Mailable with a parse error — all values in generated code are escaped.
- Blade Mailables with a loop no longer fail with `Attempt to read property on array` when rendered.
- A DTO whose optional loop merge tag is left out no longer throws a `TypeError` from `toArray()`.
- Merge tag keys that camelCase to the same name (`FIRST_NAME` and `first_name`) no longer produce a duplicate constructor parameter; they keep their raw keys as property names. Loop keys whose item DTOs would share a name are numbered.
- Blade Mailables use a view name derived from `lettr.templates.blade_path`, so a custom path resolves. A path outside the view paths prints a warning.
- `lettr:pull` and `lettr:generate-dtos` exit with a failure code when `--template` names a template that does not exist.
- A published `config/lettr.php` whose `templates` array lacks a key no longer crashes the generators with a `TypeError`; missing keys fall back to the package defaults. (`mergeConfigFrom()` only merges top-level keys.)
- Relative `lettr.templates.*` paths are resolved against `base_path()` instead of the working directory, trailing slashes are ignored, and namespaces with a leading or trailing backslash no longer generate invalid PHP.
- `lettr:pull`, `lettr:generate-dtos` and `lettr:push` no longer repeat results from an earlier run when called more than once in the same process.

## [2.3.0] - 2026-06-01

### Added

- **User-Agent identification** — the package now forwards a `lettr-laravel/<version>` suffix to the SDK client, so outbound API and SMTP requests are attributable to this integration (e.g. `User-Agent: lettr-php/<x> lettr-laravel/2.3.0`).

### Changed

- **Upgraded `lettr/lettr-php` to `^2.4.0`** for the `Lettr::client()` user-agent suffix parameter, which earlier SDK versions don't accept.

## [2.2.0] - 2026-05-28

### Added

- **Campaigns API** — Read campaigns and run their lifecycle actions through `Lettr::campaigns()`:
  - `list()` returns `CampaignSummary` items; `get()` returns a `CampaignDetail` (subclass of `CampaignSummary`) carrying the rendered `$htmlContent`
  - `events()` for cursor-paginated engagement events (`EventType` filterable)
  - `send()`, `schedule()`, `unschedule()` lifecycle actions — each returns a non-null `CampaignSummary` (the SDK transparently refetches if the API omits the campaign payload from the action response). `htmlContent` is intentionally not exposed on action results because the API doesn't include it there; call `get()` if you need the rendered body.
  - Added `campaigns()` to the `Lettr` facade and `LettrManager` docblocks (also reachable as a magic property: `Lettr::campaigns()` ↔ `app('lettr')->campaigns`)
  - See the **Campaigns** section of the README for full usage examples

### Changed

- **Upgraded `lettr/lettr-php` to `^2.3.0`** for the campaigns API. The SDK's `TransporterContract` gained `postExpectingEnvelope()` — only affects custom transporter implementations.

## [2.1.0] - 2026-05-25

### Added

- **Audience API** - Manage contacts, lists, segments, topics, and custom properties through a single `Lettr::audience()` entry point:
  - `Lettr::audience()->contacts()` / `->lists()` / `->segments()` / `->topics()` / `->properties()` expose the five audience sub-services (also reachable as magic properties, e.g. `Lettr::audience()->contacts`)
  - Contacts support creation with list attachment, custom properties, and double opt-in, plus bulk operations (bulk create, attach/detach lists, subscribe/unsubscribe topics)
  - Lists, segments, topics, and properties expose full CRUD with paginated, filterable `list()` endpoints
  - Added `audience()` to the `Lettr` facade and `LettrManager` docblocks
  - See the **Audience Management** section of the README for full usage examples

### Changed

- **Upgraded `lettr/lettr-php` to `^2.1.0`** for the audience API. The SDK's `TransporterContract` gained `patch()`, `deleteWithBody()`, and `lastStatusCode()` methods — only affects custom transporter implementations.

## [2.0.0] - 2026-04-23

Major version bump with breaking changes on two axes:

1. **Direct breaks in `lettr-laravel`'s public API** — the slug-handling surface on `TemplateServiceWrapper` and `PushCommand` has been deleted (see **Removed** below). Code that called `Lettr::templates()->slugExists()` or subclassed `PushCommand` and used its slug helpers will need updates.
2. **Transitive breaks from `lettr/lettr-php ^2.0.0`** — the SDK's DTOs, enums, and `TransporterContract` changed shape (see **Transitive SDK v2.0 changes** below).

If you only use `Mail::lettr()` / `LettrMailable` subclasses / the Laravel mail transport, and never touch `TemplateServiceWrapper::slugExists()` or subclass `PushCommand`, the upgrade is `composer update` and you're done. Otherwise audit the two sections below.

### Added

- **Scheduled Emails** - Schedule a Lettr transmission from Laravel Mail:
  - `Mail::lettr()->scheduleAt($datetime)->sendTemplate(...)` for inline templates
  - `Mail::lettr()->scheduleAt($datetime)->send($mailable)` for `LettrMailable` subclasses
  - `$this->scheduledAt($datetime)` on `LettrMailable` subclasses
  - Transport detects `X-Lettr-Scheduled-At` and routes to `POST /emails/scheduled`
- **Template Wrapper Methods** - `TemplateServiceWrapper::update()`, `::getHtml()`, and `::delete()` passthroughs for the new SDK endpoints.
- **Facade PHPDoc** - Added `projects()` and `health()` accessors to the `Lettr` facade docblock (already worked at runtime via `__get`).

### Changed

- **Upgraded `lettr/lettr-php` to `^2.0.0`.** The SDK syncs to API v1.4 (scheduled emails, email list/events, full webhook CRUD, template update/html, auth check) and was re-tagged as v2.0.0 to correctly reflect its breaking DTO/contract changes under SemVer. All new endpoints are reachable via `Lettr::emails()` / `Lettr::webhooks()` / `Lettr::templates()` / `Lettr::health()`.
- **`lettr:push`** now shows the server-assigned slug after creation. In `--dry-run` mode the summary no longer shows a client-guessed slug (the server assigns it at create time) — it prints `(slug assigned by server)` next to each template name. The `(slug conflict resolved)` yellow marker is no longer emitted — the server handles collisions.

### Removed

The underlying API has always generated slugs server-side, so client-side slug handling was dead code. Cashing in the removals now that this is a major bump:

- **`TemplateServiceWrapper::slugExists()`** — gone. Read the server-assigned slug from the `CreatedTemplate` response instead.
- **`PushCommand::resolveSlug()`** — gone. No replacement needed.
- **`PushCommand::filenameToSlug()`** — gone. Dry-run previews no longer show a client-derived slug.
- **The `$slug` parameter on `PushCommand::createTemplate()`** — signature changed from `createTemplate(string $name, string $slug, string $html)` to `createTemplate(string $name, string $html)`. Subclasses that overrode it must update their signature.
- **`PushCommand`'s `$createdTemplates` entries no longer carry a `conflict_resolved` key.**

### Transitive SDK v2.0 changes (upstream `lettr/lettr-php` breaking changes)

If you drive SDK services directly through the facade, audit these call sites:

- `Dto\Template\CreateTemplateData` no longer accepts a `slug` parameter. Drop the `slug:` arg; read the server-assigned slug from the `CreatedTemplate` response.
- `Dto\Webhook\Webhook::$eventTypes` is now `?WebhookEventTypeCollection` — `null` means the webhook subscribes to all events. Guard iteration with `$webhook->listensToAllEvents()`.
- `Enums\WebhookEventType` (namespaced: `message.delivery`, `engagement.click`, ...) replaces `Enums\EventType` in webhook subscription contexts. `Enums\EventType` (unprefixed: `delivery`, `click`, ...) remains the filter for `/emails/events`.
- `Dto\Domain\Domain` fields: removed `returnPathStatus`, `verifiedAt`; added `cnameStatus`, `statusLabel`, `updatedAt`.
- `Dto\Domain\DomainDetail` fields: removed `verifiedAt`; added `statusLabel`, `spfStatus`, `isPrimaryDomain`, `dnsProvider`.
- `Dto\Domain\DomainVerification::$ownershipVerified` retyped `?bool` → `?string`.
- `Contracts\TransporterContract` gained a required `put(string $uri, array $data): array` method. Only affects custom transporter implementations.

## [1.3.0] - 2026-03-19

### Added

- **Laravel 13 Support** - Added compatibility with Laravel 13, Symfony Mailer 8, and Orchestra Testbench 11
- **Custom Headers** - Support for custom email headers via `customHeaders()` on mailables and `customHeaders` parameter on `sendTemplate()`
- **CI Matrix** - Added Laravel 13 to GitHub Actions test matrix

## [1.2.0] - 2026-03-19

### Added

- **Custom From Address** - `from()` method on `LettrPendingMail` for sending emails from different addresses
  - `Mail::lettr()->from('hello@marketing.example.com', 'Marketing Team')->to()->sendTemplate()` fluent API
  - Works with both inline `sendTemplate()` and regular `send()` flows

### Fixed

- PHPStan: removed always-true `$depth > 0` comparisons in `BladeToSparkpostConverter` and `SparkpostToBladeConverter`

## [0.2.0] - 2025-01-23

### Added

- **Inline Template Sending** - Send Lettr templates without creating a Mailable class
  - `Mail::lettr()->to()->sendTemplate()` fluent API
  - Support for template version and project ID parameters
  - Full support for CC and BCC recipients
- **LettrPendingMail** - Extended PendingMail with `sendTemplate()` method
- **InlineLettrMailable** - Concrete mailable for inline template usage
- **Mail::fake() Support** - Full compatibility with Laravel's mail faking for tests
- **Documentation** - Added usage examples and testing documentation to README

### Changed

- LettrMailable now automatically uses the `lettr` mailer
- LettrMailable sets placeholder HTML content for Laravel compatibility
- Moved config publishing instructions to Installation section in README

## [0.1.0] - 2025-01-23

### Added

- Initial release of Lettr for Laravel
- **Laravel Mail Integration**
  - Seamless integration with Laravel's Mail system
  - Use Lettr as default mail driver or alongside other drivers
  - Full support for Mailable classes
- **LettrMailable Base Class**
  - Use Lettr templates instead of Blade views
  - Fluent API for setting template slug, version, and project ID
  - Substitution data support for template variables
- **Service Provider**
  - Auto-registration via Laravel package discovery
  - Publishes configuration via `vendor:publish`
  - Lazy-loaded Lettr client singleton
- **Lettr Facade**
  - Direct access to Email, Domain, and Webhook services
  - IDE-friendly with full PHPDoc type hints
- **Mail Transport**
  - Converts Laravel emails to Lettr API format
  - Supports HTML, plain text, and attachments
  - CC and BCC recipient support
  - Automatic Lettr template detection via headers
- **Configuration**
  - Simple API key configuration via `.env`
  - Fallback to `services.lettr.key` config
- **Laravel Support**
  - Laravel 10.x, 11.x, and 12.x compatibility
  - PHP 8.4+ required
