# Lettr for Laravel

[![CI](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Laravel integration for the [Lettr](https://lettr.com) email API.

## Requirements

- PHP 8.4+
- Laravel 10.x, 11.x, 12.x, or 13.x

## Installation

```bash
composer require lettr/lettr-laravel
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=lettr-config
```

## Getting Started

The easiest way to set up Lettr in your Laravel application is using the interactive init command:

```bash
php artisan lettr:init
```

This command will guide you through:

- **API Key Configuration** - Automatically adds your Lettr API key to `.env`
- **Mailer Setup** - Configures the Lettr mailer in `config/mail.php`
- **Template Download** - Optionally pulls your email templates as Blade files
- **Code Generation** - Generates type-safe DTOs, Mailables, and template enums
- **Domain Verification** - Checks your sending domain is properly configured

> **Tip:** If you already have a verified sending domain in your [Lettr account](https://app.lettr.com/domains/sending), the init command will automatically configure your `MAIL_FROM_ADDRESS` to match it.

After running `lettr:init`, you're ready to send emails:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\Lettr\WelcomeEmail;

// Using a generated Mailable
Mail::to('user@example.com')->send(new WelcomeEmail($data));

// Or send templates inline
Mail::lettr()->to('user@example.com')->sendTemplate('welcome-email', substitutionData: $data);
```

## Manual Setup

If you prefer to configure manually, add your [Lettr API key](https://app.lettr.com) to your `.env` file:

```ini
LETTR_API_KEY=your-api-key
```

### Sending Domain

To send emails through Lettr, you must have a verified sending domain in your [Lettr account](https://app.lettr.com/domains/sending). Your `MAIL_FROM_ADDRESS` (or any "from" address you use) must match a verified domain.

For example, if you've verified `example.com` in Lettr:

```ini
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="My App"
```

Emails sent from addresses on unverified domains will be rejected.

## Quick Start

### Using Laravel Mail (Recommended)

Add the Lettr mailer to your `config/mail.php`:

```php
'mailers' => [
    // ... other mailers

    'lettr' => [
        'transport' => 'lettr',
    ],
],
```

Set as default in `.env`:

```ini
MAIL_MAILER=lettr
```

Send emails using Laravel's Mail facade:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeEmail;

Mail::to('recipient@example.com')->send(new WelcomeEmail());
```

### Using the Lettr Facade Directly

```php
use Lettr\Laravel\Facades\Lettr;

$response = Lettr::emails()->send(
    Lettr::emails()->create()
        ->from('sender@example.com', 'Sender Name')
        ->to(['recipient@example.com'])
        ->subject('Hello from Lettr')
        ->html('<h1>Hello!</h1><p>This is a test email.</p>')
);

echo $response->requestId; // Request ID for tracking
echo $response->accepted;  // Number of accepted recipients
```

## Laravel Mail Integration

### With Mailable Classes

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderConfirmation;

// Send using Mailable
Mail::to('customer@example.com')
    ->cc('sales@example.com')
    ->bcc('records@example.com')
    ->send(new OrderConfirmation($order));
```

### With Raw Content

```php
Mail::raw('Plain text email content', function ($message) {
    $message->to('recipient@example.com')
            ->subject('Quick Update');
});
```

### With Views

```php
Mail::send('emails.welcome', ['user' => $user], function ($message) {
    $message->to('recipient@example.com')
            ->subject('Welcome!');
});
```

### Multiple Mail Drivers

Use Lettr for specific emails while keeping another default:

```php
// Use Lettr for this specific email
Mail::mailer('lettr')
    ->to('recipient@example.com')
    ->send(new TransactionalEmail());

// Uses default mailer
Mail::to('other@example.com')
    ->send(new MarketingEmail());
```

## Using Lettr Templates with Mailables

Instead of using Blade views, you can send emails using Lettr templates directly. Extend the `LettrMailable` class:

```php
<?php

namespace App\Mail;

use Lettr\Laravel\Mail\LettrMailable;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeEmail extends LettrMailable
{
    public function __construct(
        public string $userName,
        public string $activationUrl,
    ) {}

    public function build(): static
    {
        return $this
            ->template('welcome-email', version: 2)
            ->substitutionData([
                'user_name' => $this->userName,
                'activation_url' => $this->activationUrl,
            ]);
    }
}
```

Then send it like any other Mailable:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeEmail;

Mail::to('user@example.com')
    ->send(new WelcomeEmail(
        userName: 'John',
        activationUrl: 'https://example.com/activate/abc123'
    ));
```

### LettrMailable Methods

| Method | Description |
|--------|-------------|
| `template($slug, $version)` | Set template slug with optional version |
| `templateVersion($version)` | Set template version separately |
| `substitutionData($data)` | Set substitution variables for the template |
| `customHeaders($headers)` | Set custom email headers |
| `scheduledAt($when)` | Schedule delivery for a future `DateTimeInterface` (or ISO-8601 string) |

### Example: Order Confirmation

```php
class OrderConfirmation extends LettrMailable
{
    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order #{$this->order->id} Confirmed",
        );
    }

    public function build(): static
    {
        return $this
            ->template('order-confirmation')
            ->substitutionData([
                'order_id' => $this->order->id,
                'customer_name' => $this->order->customer->name,
                'items' => $this->order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'price' => $item->formatted_price,
                ])->toArray(),
                'total' => $this->order->formatted_total,
                'shipping_address' => $this->order->shipping_address,
            ]);
    }
}
```

## Inline Template Sending

For quick template sending without creating a Mailable class, use the `Mail::lettr()` method:

> **Note:** When no subject is provided, the template's own subject is used. Pass a `subject` only if you want to override it.

```php
use Illuminate\Support\Facades\Mail;

// Simple usage — subject comes from the template
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', substitutionData: ['name' => 'John']);

// Override the template's subject
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', subject: 'Hey John!', substitutionData: ['name' => 'John']);

// With specific template version
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('order-confirmation', substitutionData: [
        'order_id' => 123,
        'items' => $items,
    ], version: 2);

// With a custom from address
Mail::lettr()
    ->from('hello@marketing.example.com', 'Marketing Team')
    ->to('user@example.com')
    ->sendTemplate('promo-campaign', substitutionData: $promoData);

// With CC and BCC
Mail::lettr()
    ->to('user@example.com')
    ->cc('manager@example.com')
    ->bcc('records@example.com')
    ->sendTemplate('invoice', substitutionData: $invoiceData);

// With a generated DTO (implements Arrayable)
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', substitutionData: new WelcomeEmailData(
        userName: 'John',
        activationUrl: 'https://example.com/activate/abc123',
    ));
```

### Custom From Address

By default, emails are sent from the address configured in `MAIL_FROM_ADDRESS`. To send from a different address (e.g. a marketing domain), use `from()`:

```php
// Inline template sending
Mail::lettr()
    ->from('hello@marketing.example.com', 'Marketing Team')
    ->to('user@example.com')
    ->sendTemplate('promo-campaign', substitutionData: $promoData);

// Regular Mailable sending
Mail::lettr()
    ->from('noreply@transactional.example.com')
    ->to('user@example.com')
    ->send(new OrderConfirmation($order));
```

For Mailable classes, you can also set the from address in the `envelope()` method:

```php
class MarketingEmail extends LettrMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('hello@marketing.example.com', 'Marketing Team'),
            subject: 'Special Offer',
        );
    }
}
```

> **Note:** The from address must belong to a verified sending domain in your [Lettr account](https://app.lettr.com/domains/sending).

### Custom Headers

You can pass custom headers with your emails. These are forwarded directly to the Lettr API.

```php
// Inline template sending
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', substitutionData: ['name' => 'John'], customHeaders: [
        'X-Campaign-Id' => 'welcome-2024',
        'X-Entity-Ref' => 'order-123',
    ]);
```

For Mailable classes, use the `customHeaders()` method:

```php
class WelcomeEmail extends LettrMailable
{
    public function build(): static
    {
        return $this
            ->template('welcome-email')
            ->customHeaders([
                'X-Campaign-Id' => 'welcome-2024',
                'X-Entity-Ref' => 'order-123',
            ]);
    }
}
```

## Error Handling

```php
use Lettr\Exceptions\ApiException;
use Lettr\Exceptions\ContactAlreadyExistsException;
use Lettr\Exceptions\TransporterException;
use Lettr\Exceptions\ValidationException;
use Lettr\Exceptions\NotFoundException;
use Lettr\Exceptions\UnauthorizedException;
use Lettr\Exceptions\RateLimitException;
use Lettr\Exceptions\QuotaExceededException;

try {
    $response = Lettr::emails()->send($email);
} catch (ContactAlreadyExistsException $e) {
    // Duplicate contact (409) — client-correctable, never retry
    Log::info("Contact already exists: " . $e->email);
} catch (RateLimitException $e) {
    // Too many requests (429)
    Log::warning("Rate limited, retry after: " . $e->retryAfter . "s");
} catch (QuotaExceededException $e) {
    // Sending quota exceeded
    Log::error("Quota exceeded: " . $e->getMessage());
} catch (ValidationException $e) {
    // Invalid request data (422)
    Log::error("Validation failed: " . $e->getMessage());
} catch (UnauthorizedException $e) {
    // Invalid API key (401)
    Log::error("Authentication failed: " . $e->getMessage());
} catch (NotFoundException $e) {
    // Resource not found (404)
    Log::error("Not found: " . $e->getMessage());
} catch (ApiException $e) {
    // Other API errors
    Log::error("API error ({$e->getCode()}): " . $e->getMessage());
} catch (TransporterException $e) {
    // Network/transport errors
    Log::error("Network error: " . $e->getMessage());
}
```

Every `ApiException` exposes the API's machine-readable code via `$e->errorCode()` (or the readonly `$e->errorCode`), or `null` when the API didn't send one. Catch order matters: `ContactAlreadyExistsException` extends `ConflictException`, which extends `ApiException`, so it must be caught before them.

Creating a contact whose email is already in your audience throws `ContactAlreadyExistsException` (HTTP 409, `resource_already_exists`) carrying the colliding `$e->email`. **Don't retry it** — it's a client-correctable condition, not an outage. Update the existing contact with `update()`, or use `bulkCreate()` with `updateExisting: true`.

## Configuration

The published `config/lettr.php` file contains:

```php
return [
    'api_key' => env('LETTR_API_KEY'),

    'templates' => [
        'html_path' => resource_path('templates/lettr'),
        'blade_path' => resource_path('views/emails/lettr'),
        'mailable_path' => app_path('Mail/Lettr'),
        'mailable_namespace' => 'App\\Mail\\Lettr',
        'dto_path' => app_path('Dto/Lettr'),
        'dto_namespace' => 'App\\Dto\\Lettr',
        'enum_path' => app_path('Enums'),
        'enum_namespace' => 'App\\Enums',
        'enum_class' => 'LettrTemplate',
    ],
];
```

The `templates` block configures where `lettr:pull`, `lettr:generate-dtos`, and `lettr:generate-enum` commands save generated files.

The package also supports `config('services.lettr.key')` as a fallback for the API key.

## Documentation

Full guides, every facade method, and complete request/response details live in the docs:

📚 **[docs.lettr.com/quickstart/laravel](https://docs.lettr.com/quickstart/laravel/introduction)**

| Topic | Guide |
|-|-|
| Install, config, and verify | [Installation](https://docs.lettr.com/quickstart/laravel/installation) |
| Mail facade, Lettr facade, Mailables, scheduling, testing | [Sending Emails](https://docs.lettr.com/quickstart/laravel/sending-emails) |
| Lettr templates, versioning, pull/push | [Templates](https://docs.lettr.com/quickstart/laravel/templates) |
| Generated enums, DTOs, and Mailables | [Type Safety](https://docs.lettr.com/quickstart/laravel/type-safety) |
| Add, verify, and manage sending domains | [Domains](https://docs.lettr.com/quickstart/laravel/domains) |
| Webhook endpoints for delivery & engagement events | [Webhooks](https://docs.lettr.com/quickstart/laravel/webhooks) |
| Lists, contacts, topics, properties, segments | [Audience](https://docs.lettr.com/quickstart/laravel/audience) |
| List, send, and schedule campaigns | [Campaigns](https://docs.lettr.com/quickstart/laravel/campaigns) |
| Endpoint reference (params & schemas) | [API Reference](https://docs.lettr.com/api-reference/introduction) |


## CLI Commands

### `lettr:check`

Verify that your Lettr integration is correctly configured:

```bash
php artisan lettr:check
```

Checks mailer registration, API key validity, and sending domain verification. Returns exit code 0 if all checks pass.

### `lettr:pull`

Download email templates from your Lettr account as Blade files:

```bash
php artisan lettr:pull
php artisan lettr:pull --template=welcome-email
php artisan lettr:pull --as-html
php artisan lettr:pull --with-mailables
php artisan lettr:pull --dry-run
```

| Option | Description |
|--------|-------------|
| `--template=` | Pull only a specific template by slug |
| `--as-html` | Save as raw HTML instead of Blade |
| `--with-mailables` | Also generate Mailable and DTO classes |
| `--skip-templates` | Skip downloading templates, only generate DTOs and Mailables |
| `--dry-run` | Preview what would be downloaded |

### `lettr:push`

Upload local Blade email templates to your Lettr account, the reverse of `lettr:pull`:

```bash
php artisan lettr:push
php artisan lettr:push --path=resources/views/emails
php artisan lettr:push --template=welcome-email
php artisan lettr:push --purpose=campaign
php artisan lettr:push --dry-run
```

| Option | Description |
|--------|-------------|
| `--path=` | Custom path to the templates directory (auto-discovered otherwise) |
| `--template=` | Push only a specific template by filename |
| `--purpose=` | Module to create the templates in — `transactional` (default) or `campaign` |
| `--dry-run` | Preview what would be created without pushing |

Blade syntax is converted to Sparkpost syntax on the way up. Without `--purpose` no module is sent at all and the API applies its own default, which is `transactional` — the right one for Blade mailables.

### `lettr:generate-enum`

Generate a PHP enum from your Lettr template slugs for type-safe template references:

```bash
php artisan lettr:generate-enum
php artisan lettr:generate-enum --dry-run
```

Generates an enum like:

```php
enum LettrTemplate: string
{
    case WelcomeEmail = 'welcome-email';
    case OrderConfirmation = 'order-confirmation';
}
```

### `lettr:generate-dtos`

Generate type-safe DTO classes from template merge tags:

```bash
php artisan lettr:generate-dtos
php artisan lettr:generate-dtos --template=welcome-email
php artisan lettr:generate-dtos --dry-run
```

Generated DTOs implement `Arrayable` and can be passed directly to `sendTemplate()`:

```php
$data = new WelcomeEmailData(userName: 'John', activationUrl: '...');

Mail::lettr()->to('user@example.com')->sendTemplate('welcome-email', substitutionData: $data);
```

## Development

### Install Dependencies

```bash
composer install
```

### Code Style

```bash
composer lint
```

### Static Analysis

```bash
composer analyse
```

### Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

MIT License. See [LICENSE](LICENSE) for details.
