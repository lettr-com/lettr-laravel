<?php

/**
 * README Documentation Tests
 *
 * This file tests that all code examples in README.md work exactly as documented.
 * It is generated/maintained by the readme-doc-test skill — do not edit manually.
 *
 * Every test here must correspond to an example that is currently in README.md.
 * Tests for SDK surface the README no longer documents live in
 * tests/Unit/SdkSurfaceTest.php instead — do not move them back.
 */

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Mail;
use Lettr\Builders\EmailBuilder;
use Lettr\Laravel\Facades\Lettr;
use Lettr\Laravel\Mail\InlineLettrMailable;
use Lettr\Laravel\Mail\LettrMailable;
use Lettr\Laravel\Mail\LettrPendingMail;

// ---------------------------------------------------------------------------
// Section: Using the Lettr Facade Directly — Email Builder
// ---------------------------------------------------------------------------

it('emails service create returns an email builder', function () {
    $builder = Lettr::emails()->create();

    expect($builder)->toBeInstanceOf(EmailBuilder::class);
});

it('email builder supports fluent from to subject html chain', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com', 'Sender Name')
        ->to(['recipient@example.com'])
        ->subject('Hello from Lettr')
        ->html('<h1>Hello!</h1><p>This is a test email.</p>');

    expect($email)->toBeInstanceOf(EmailBuilder::class);
    $data = $email->build();
    expect($data->from->address)->toBe('sender@example.com');
    expect($data->from->name)->toBe('Sender Name');
    expect($data->subject->value)->toBe('Hello from Lettr');
    expect($data->html)->toBe('<h1>Hello!</h1><p>This is a test email.</p>');
});

// ---------------------------------------------------------------------------
// Section: Using Lettr Templates with Mailables (LettrMailable)
// ---------------------------------------------------------------------------

it('LettrMailable template method sets slug and version and returns static', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->template('welcome-email', version: 2);
        }
    };

    $result = $mailable->build();

    expect($result)->toBeInstanceOf(LettrMailable::class);

    $reflection = new ReflectionClass($result);
    $slug = $reflection->getProperty('templateSlug');
    $slug->setAccessible(true);
    $version = $reflection->getProperty('templateVersion');
    $version->setAccessible(true);

    expect($slug->getValue($result))->toBe('welcome-email');
    expect($version->getValue($result))->toBe(2);
});

it('LettrMailable templateVersion method sets version separately', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->template('welcome-email')->templateVersion(3);
        }
    };

    $result = $mailable->build();

    $reflection = new ReflectionClass($result);
    $version = $reflection->getProperty('templateVersion');
    $version->setAccessible(true);

    expect($version->getValue($result))->toBe(3);
});

it('LettrMailable substitutionData method merges data into the mailable', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this
                ->template('welcome-email', version: 2)
                ->substitutionData([
                    'user_name' => 'John',
                    'activation_url' => 'https://example.com/activate/abc123',
                ]);
        }
    };

    $result = $mailable->build();

    $reflection = new ReflectionClass($result);
    $data = $reflection->getProperty('substitutionData');
    $data->setAccessible(true);

    expect($data->getValue($result))->toBe([
        'user_name' => 'John',
        'activation_url' => 'https://example.com/activate/abc123',
    ]);
});

// ---------------------------------------------------------------------------
// Section: Inline Template Sending (Mail lettr)
// ---------------------------------------------------------------------------

it('Mail lettr returns a LettrPendingMail instance', function () {
    $result = Mail::lettr();

    expect($result)->toBeInstanceOf(LettrPendingMail::class);
});

it('can send template inline using Mail lettr with fake', function () {
    Mail::fake();

    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('welcome-email', substitutionData: ['name' => 'John']);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com');
    });
});

it('can override template subject when sending inline', function () {
    Mail::fake();

    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('welcome-email', subject: 'Hey John!', substitutionData: ['name' => 'John']);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com');
    });
});

it('can specify template version when sending inline', function () {
    Mail::fake();

    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('order-confirmation', substitutionData: [
            'order_id' => 123,
            'items' => [],
        ], version: 2);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com');
    });
});

it('can use cc and bcc when sending template inline', function () {
    Mail::fake();

    Mail::lettr()
        ->to('user@example.com')
        ->cc('manager@example.com')
        ->bcc('records@example.com')
        ->sendTemplate('invoice', substitutionData: ['amount' => 99.99]);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com')
            && $mailable->hasCc('manager@example.com')
            && $mailable->hasBcc('records@example.com');
    });
});

it('can pass an Arrayable DTO as substitution data when sending inline', function () {
    Mail::fake();

    $dto = new class implements Arrayable
    {
        public function toArray(): array
        {
            return [
                'userName' => 'John',
                'activationUrl' => 'https://example.com/activate/abc123',
            ];
        }
    };

    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('welcome-email', substitutionData: $dto);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('user@example.com');
    });
});
