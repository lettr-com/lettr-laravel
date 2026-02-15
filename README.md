# Lettr for Laravel

[![CI](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Laravel integration for the [Lettr](https://lettr.com) email API.

## Requirements

- PHP 8.4+
- Laravel 10.x, 11.x, or 12.x

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
Mail::lettr()->to('user@example.com')->sendTemplate('welcome-email', $data);
```

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

Push local Blade templates to your Lettr account:

```bash
php artisan lettr:push --path=resources/views/emails
php artisan lettr:push --template=welcome-email
php artisan lettr:push --dry-run
```

Automatically converts Blade syntax to Lettr merge tag syntax and resolves slug conflicts.

| Option | Description |
|--------|-------------|
| `--path=` | Custom path to templates directory |
| `--template=` | Push only a specific template by filename |
| `--dry-run` | Preview what would be created |

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

Mail::lettr()->to('user@example.com')->sendTemplate('welcome-email', $data);
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

    public function envelope(): Envelope
    {
        return new Envelope(
            from: 'hello@example.com',
            subject: 'Welcome to Our App!',
        );
    }

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

```php
use Illuminate\Support\Facades\Mail;

// Simple usage
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', ['name' => 'John']);

// With specific template version
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('order-confirmation', [
        'order_id' => 123,
        'items' => $items,
    ], version: 2);

// With CC and BCC
Mail::lettr()
    ->to('user@example.com')
    ->cc('manager@example.com')
    ->bcc('records@example.com')
    ->sendTemplate('invoice', $invoiceData);

// With a generated DTO (implements Arrayable)
Mail::lettr()
    ->to('user@example.com')
    ->sendTemplate('welcome-email', new WelcomeEmailData(
        userName: 'John',
        activationUrl: 'https://example.com/activate/abc123',
    ));
```

### Testing with Mail::fake()

The `Mail::lettr()` method works seamlessly with Laravel's `Mail::fake()` for testing:

```php
use Illuminate\Support\Facades\Mail;
use Lettr\Laravel\Mail\InlineLettrMailable;

public function test_welcome_email_is_sent(): void
{
    Mail::fake();

    // Trigger the code that sends the email
    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('welcome-email', ['name' => 'John']);

    // Assert the email was sent
    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com');
    });
}

public function test_order_confirmation_has_correct_recipients(): void
{
    Mail::fake();

    Mail::lettr()
        ->to('customer@example.com')
        ->cc('sales@example.com')
        ->bcc('records@example.com')
        ->sendTemplate('order-confirmation', ['order_id' => 123]);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('customer@example.com')
            && $mailable->hasCc('sales@example.com')
            && $mailable->hasBcc('records@example.com');
    });
}
```

## Direct API Usage

### Sending Emails

#### Using the Email Builder (Recommended)

```php
use Lettr\Laravel\Facades\Lettr;

$response = Lettr::emails()->send(
    Lettr::emails()->create()
        ->from('sender@example.com', 'Sender Name')
        ->to(['recipient@example.com'])
        ->cc(['cc@example.com'])
        ->bcc(['bcc@example.com'])
        ->replyTo('reply@example.com')
        ->subject('Welcome!')
        ->html('<h1>Welcome</h1>')
        ->text('Welcome (plain text fallback)')
        ->transactional()
        ->withClickTracking(true)
        ->withOpenTracking(true)
        ->metadata(['user_id' => '123', 'campaign' => 'welcome'])
        ->substitutionData(['name' => 'John', 'company' => 'Acme'])
        ->tag('welcome')
);
```

#### Quick Send Methods

```php
// HTML email
$response = Lettr::emails()->sendHtml(
    from: 'sender@example.com',
    to: 'recipient@example.com',
    subject: 'Hello',
    html: '<p>HTML content</p>',
);

// Plain text email
$response = Lettr::emails()->sendText(
    from: ['email' => 'sender@example.com', 'name' => 'Sender'],
    to: ['recipient1@example.com', 'recipient2@example.com'],
    subject: 'Hello',
    text: 'Plain text content',
);

// Template email
$response = Lettr::emails()->sendTemplate(
    from: 'sender@example.com',
    to: 'recipient@example.com',
    subject: 'Welcome!',
    templateSlug: 'welcome-email',
    templateVersion: 2,
    substitutionData: ['name' => 'John'],
);
```

### Attachments

```php
use Lettr\Dto\Email\Attachment;

$email = Lettr::emails()->create()
    ->from('sender@example.com')
    ->to(['recipient@example.com'])
    ->subject('Document attached')
    ->html('<p>Please find the document attached.</p>')
    // From file path
    ->attachFile('/path/to/document.pdf')
    // With custom name and mime type
    ->attachFile('/path/to/file', 'custom-name.pdf', 'application/pdf')
    // From binary data
    ->attachData($binaryContent, 'report.csv', 'text/csv')
    // Using Attachment DTO
    ->attach(Attachment::fromFile('/path/to/image.png'));

$response = Lettr::emails()->send($email);
```

### Templates with Substitution Data

```php
$response = Lettr::emails()->send(
    Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->subject('Your Order #{{order_id}}')
        ->useTemplate('order-confirmation', version: 1)
        ->substitutionData([
            'order_id' => '12345',
            'customer_name' => 'John Doe',
            'items' => [
                ['name' => 'Product A', 'price' => 29.99],
                ['name' => 'Product B', 'price' => 49.99],
            ],
            'total' => 79.98,
        ])
);
```

### Email Options

```php
$email = Lettr::emails()->create()
    ->from('sender@example.com')
    ->to(['recipient@example.com'])
    ->subject('Newsletter')
    ->html($htmlContent)
    // Tracking
    ->withClickTracking(true)
    ->withOpenTracking(true)
    // Mark as non-transactional (marketing email, respects unsubscribe lists)
    ->transactional(false)
    // CSS inlining
    ->withInlineCss(true)
    // Template variable substitution
    ->withSubstitutions(true);
```

### Retrieving Emails

#### Get Email Events by Request ID

```php
use Lettr\Enums\EventType;

// After sending
$response = Lettr::emails()->send($email);
$requestId = $response->requestId;

// Later, retrieve events
$result = Lettr::emails()->get($requestId);

foreach ($result->events as $event) {
    echo $event->type->value;      // 'delivery', 'open', 'click', etc.
    echo $event->recipient;        // Recipient email
    echo $event->timestamp;        // When the event occurred

    // Event-specific data
    if ($event->type === EventType::Click) {
        echo $event->clickUrl;
    }
    if ($event->type === EventType::Bounce) {
        echo $event->bounceClass;
        echo $event->reason;
    }
}
```

#### List Email Events with Filtering

```php
use Lettr\Dto\Email\ListEmailsFilter;

// List all events
$result = Lettr::emails()->list();

// With filters
$filter = ListEmailsFilter::create()
    ->perPage(50)
    ->forRecipient('user@example.com')
    ->fromDate('2024-01-01')
    ->toDate('2024-12-31');

$result = Lettr::emails()->list($filter);

echo $result->totalCount;
echo $result->pagination->hasNextPage();

// Paginate through results
while ($result->hasMore()) {
    foreach ($result->events as $event) {
        // Process event
    }

    $filter = $filter->cursor($result->pagination->nextCursor);
    $result = Lettr::emails()->list($filter);
}
```

## Domain Management

### List Domains

```php
$domains = Lettr::domains()->list();

foreach ($domains as $domain) {
    echo $domain->domain;           // example.com
    echo $domain->status->value;    // 'pending', 'approved'
    echo $domain->canSend;          // true/false
}
```

### Add a Domain

```php
use Lettr\ValueObjects\DomainName;

$result = Lettr::domains()->create('example.com');

echo $result->domain;
echo $result->status;

// DNS records to configure
echo $result->dns->returnPathHost;
echo $result->dns->returnPathValue;

if ($result->dns->dkim !== null) {
    echo $result->dns->dkim->selector;
    echo $result->dns->dkim->publicKey;
}
```

### Verify Domain DNS

```php
$verification = Lettr::domains()->verify('example.com');

if ($verification->isFullyVerified()) {
    echo "Domain is ready to send!";
} else {
    if (!$verification->dkim->isValid()) {
        echo "DKIM error: " . $verification->dkim->error;
    }
    if (!$verification->returnPath->isValid()) {
        echo "Return path error: " . $verification->returnPath->error;
    }
}
```

### Get Domain Details

```php
$domain = Lettr::domains()->get('example.com');

echo $domain->domain;
echo $domain->status;
echo $domain->trackingDomain;
echo $domain->createdAt;
```

### Delete a Domain

```php
Lettr::domains()->delete('example.com');
```

## Webhooks

### List Webhooks

```php
$webhooks = Lettr::webhooks()->list();

foreach ($webhooks as $webhook) {
    echo $webhook->id;
    echo $webhook->name;
    echo $webhook->url;
    echo $webhook->enabled;
    echo $webhook->authType->value;  // 'none', 'basic', 'oauth2'

    foreach ($webhook->eventTypes as $eventType) {
        echo $eventType->value;
    }

    if ($webhook->isFailing()) {
        echo "Last error: " . $webhook->lastError;
    }
}
```

### Get Webhook Details

```php
use Lettr\Enums\EventType;

$webhook = Lettr::webhooks()->get('webhook-id');

echo $webhook->name;
echo $webhook->url;
echo $webhook->lastTriggeredAt;

if ($webhook->listensTo(EventType::Bounce)) {
    echo "Webhook receives bounce notifications";
}
```

## Event Types

The SDK provides an `EventType` enum with helper methods:

```php
use Lettr\Enums\EventType;

$type = EventType::Delivery;

$type->label();        // "Delivery"
$type->isSuccess();    // true (injection, delivery)
$type->isFailure();    // false (bounce, policy_rejection, etc.)
$type->isEngagement(); // false (open, initial_open, click)
$type->isUnsubscribe(); // false (list_unsubscribe, link_unsubscribe)
```

Available event types: `injection`, `delivery`, `bounce`, `delay`, `policy_rejection`, `out_of_band`, `open`, `initial_open`, `click`, `generation_failure`, `generation_rejection`, `spam_complaint`, `list_unsubscribe`, `link_unsubscribe`

## Error Handling

```php
use Lettr\Exceptions\ApiException;
use Lettr\Exceptions\TransporterException;
use Lettr\Exceptions\ValidationException;
use Lettr\Exceptions\NotFoundException;
use Lettr\Exceptions\UnauthorizedException;
use Lettr\Exceptions\RateLimitException;
use Lettr\Exceptions\QuotaExceededException;

try {
    $response = Lettr::emails()->send($email);
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
