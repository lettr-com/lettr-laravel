# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [2.5.0] - 2026-09-07

Covers the marketing side of templates. Everything here is additive — code written against 2.4.0 keeps compiling and sends byte-identical requests.

### Added

- **`Lettr::folders()`** — lists the folders templates are filed into, wrapping the SDK's new `FolderService`. This is what `CreateTemplateData::$folderId` was missing: nothing in the package ever returned a folder id, so a caller either omitted `folderId` and accepted whichever folder the API picked, or hardcoded an integer read out of an app URL by hand.

  ```php
  use Lettr\Dto\Folder\ListFoldersFilter;
  use Lettr\Dto\Template\CreateTemplateData;
  use Lettr\Enums\TemplatePurpose;
  use Lettr\Laravel\Facades\Lettr;

  $campaigns = Lettr::folders()
      ->list(ListFoldersFilter::create()->purpose(TemplatePurpose::Campaign))
      ->folders
      ->first();

  Lettr::templates()->create(new CreateTemplateData(
      name: 'October Newsletter',
      folderId: $campaigns?->id,
      json: $topolJson,
      purpose: TemplatePurpose::Campaign,
  ));
  ```

  Each folder carries its `purpose` and `templatesCount`, so a campaign folder can be picked without guessing from the name. Read-only — creating, renaming and deleting folders stay in the app, because deleting one moves or deletes the templates inside it.
- **Template `purpose`** throughout, via the SDK's new `Lettr\Enums\TemplatePurpose` (`Transactional`, `Campaign`): settable on `CreateTemplateData`, filterable on `ListTemplatesFilter`, and returned on every template response DTO. `Lettr::templates()` passes the DTOs straight through, so this needed no wrapper change.
- **`lettr:push --purpose=`** — creates the pushed templates in one module. Without it no `purpose` key is sent at all and the API applies its own default (`transactional`), which is the right one for Blade mailables; the flag is there for the case where campaign HTML lives in the same directory. An unrecognised value fails the command before it touches the filesystem. `lettr:push` also picked up a README section — it was the one CLI command with none.

### Changed

- **Upgraded `lettr/lettr-php` to `^2.6.0`** for the purpose enum and the folder endpoint. The SDK change is additive: new constructor parameters were appended last on the existing DTOs, so positional construction keeps working, and `TransporterContract` is untouched, so custom transporter implementations are unaffected.

### Notes

- **There is no way to change a template's module after the fact.** `PUT /templates/{slug}` does not accept a `purpose`, so `UpdateTemplateData` deliberately has none — moving a template across modules has to copy its versions and merge tags into the other module's folder, which is a separate endpoint that does not exist yet. Set the purpose when you create the template.
- **A response without `purpose` reads as `Transactional`.** That is what such a template is, so the field is a plain enum rather than a nullable one and never needs a null check — but it does mean an older API deployment is indistinguishable from a genuinely transactional template.

## [2.4.0] - 2026-08-14

### Added

- **Reworked bulk contact import** — reachable through `Lettr::audience()->contacts()`:
  - `BulkCreateAudienceContactsData::forContacts()` addresses each contact individually — every `BulkAudienceContactRow` carries its own `properties`, `listIds` and `topics`, on top of the batch-wide values. `::forEmails()` names the original flat shape; the plain constructor still accepts `(emails, listId, properties)` positionally, so existing calls send byte-identical payloads.
  - Topic subscriptions are expressed as `AudienceTopicSubscription::optIn()` / `::optOut()` (enum `AudienceTopicSubscriptionState`). A row-level `optOut()` suppresses the auto-subscription of an opt-out-by-default topic **in the same request**, and beats a batch-level opt-in for that contact.
  - `updateExisting: true` merges properties on existing contacts (submitted keys overwrite, absent keys are preserved). The default `false` still attaches existing contacts to the requested lists — unchanged behaviour.
  - `BulkStoreAudienceContactsResult` now reports what happened per row: `updated`, `errorCount`, `errors` (`BulkAudienceContactError[]`), and `contacts` (`BulkAudienceContactRef[]`, so you get ids back without a follow-up lookup), plus the helpers `hasErrors()`, `contactIds()` and `idFor(string $email)`.
- **Bulk topic subscribe/unsubscribe** — `contacts()->bulkSubscribeTopics()` and `->bulkUnsubscribeTopics()`, both taking a `BulkAudienceContactTopicsData` (`contactIds` × `topicIds`, up to 1000 × 50). Feed them `$result->contactIds()` straight from a bulk create.
- **`ContactAlreadyExistsException`** — creating a contact whose email already exists now throws a dedicated exception (HTTP 409, `resource_already_exists`) carrying the colliding `->email`, instead of surfacing as an HTTP 500 with the misleading `send_error` code. It extends `ConflictException` → `ApiException`, so existing handlers keep catching it. See the **Error Handling** section of the README.
- **`ApiException::errorCode()`** (and the readonly `->errorCode`) exposes the API's machine-readable `error_code` on every API exception, or `null` when the API didn't send one.

### Changed

- **Upgraded `lettr/lettr-php` to `^2.5.0`** for the bulk contact import rework. The SDK change is additive — `TransporterContract` is untouched, so custom transporter implementations are unaffected.

### Notes

Three behaviours in the new bulk create are easy to get wrong:

- **A bulk create can partially succeed.** Rows that fail validation are skipped and returned in `errors` while the rest of the batch commits — and the call still returns HTTP 201. Check `hasErrors()`; don't read a successful return as "everything landed".
- **`alreadyExisted` and `updated` overlap by design.** They answer different questions ("was the address already in the audience?" vs "did this request change the contact?"), so they don't sum to the row count: a contact that already existed and got attached to a list is counted in both.
- **An empty payload now throws.** `new BulkCreateAudienceContactsData([])` raises `InvalidValueException` instead of being sent to the API — the one non-additive edge of the SDK upgrade.

Duplicate contact creates are also no longer picked up by a 5xx retry policy, since they now arrive as a 409. `withRateLimitRetry()` in this package only ever retried `RateLimitException`, so it is unaffected.

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
