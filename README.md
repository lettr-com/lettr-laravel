# Lettr for Laravel

[![CI](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/TOPOL-io/lettr-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)
[![License](https://img.shields.io/packagist/l/lettr/lettr-laravel.svg)](https://packagist.org/packages/lettr/lettr-laravel)

Official Laravel integration for the [Lettr](https://lettr.com) email API.

## Requirements

- PHP 8.4+
- Laravel 10.x, 11.x, or 12.x

## Installation

```bash
composer require lettr/lettr-laravel
```

Add your [Lettr API key](https://app.lettr.com) to your `.env` file:

```ini
LETTR_API_KEY=your-api-key
```

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
            ->template('welcome-email', version: 2, projectId: 123)
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
| `template($slug, $version, $projectId)` | Set template slug with optional version and project |
| `templateVersion($version)` | Set template version separately |
| `projectId($projectId)` | Set project ID separately |
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
            ->projectId(config('services.lettr.project_id'))
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
        ->campaignId('welcome-series')
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
    projectId: 123,
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
        ->useTemplate('order-confirmation', version: 1, projectId: 123)
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
    // Mark as transactional (bypasses unsubscribe lists)
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
    echo $webhook->authType->value;  // 'none', 'basic', 'bearer'

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

try {
    $response = Lettr::emails()->send($email);
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

Publish the configuration file:

```bash
php artisan vendor:publish --tag=lettr-config
```

This creates `config/lettr.php`:

```php
return [
    'api_key' => env('LETTR_API_KEY'),
];
```

The package also supports `config('services.lettr.key')` as a fallback.

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
