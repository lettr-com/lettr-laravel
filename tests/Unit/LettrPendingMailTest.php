<?php

use Illuminate\Support\Facades\Mail;
use Lettr\Laravel\Mail\InlineLettrMailable;
use Lettr\Laravel\Mail\LettrPendingMail;

it('can access lettr method on mailer', function () {
    $pendingMail = Mail::lettr();

    expect($pendingMail)->toBeInstanceOf(LettrPendingMail::class);
});

it('can chain to and sendTemplate methods', function () {
    Mail::fake();

    Mail::lettr()
        ->to('test@example.com')
        ->sendTemplate('welcome-email', substitutionData: ['name' => 'John']);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('test@example.com');
    });
});

it('can use cc and bcc with sendTemplate', function () {
    Mail::fake();

    Mail::lettr()
        ->to('test@example.com')
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->sendTemplate('order-confirmation', substitutionData: ['order_id' => 123]);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        return $mailable->hasTo('test@example.com')
            && $mailable->hasCc('cc@example.com')
            && $mailable->hasBcc('bcc@example.com');
    });
});

it('works with Mail::fake()', function () {
    Mail::fake();

    Mail::lettr()
        ->to('user@example.com')
        ->sendTemplate('test-template', substitutionData: ['foo' => 'bar']);

    Mail::assertSent(InlineLettrMailable::class);
});

it('accepts Arrayable objects in sendTemplate', function () {
    Mail::fake();

    $dto = new class implements \Illuminate\Contracts\Support\Arrayable
    {
        public function toArray(): array
        {
            return ['name' => 'John Doe', 'email' => 'john@example.com'];
        }
    };

    Mail::lettr()
        ->to('test@example.com')
        ->sendTemplate('user-welcome', substitutionData: $dto);

    Mail::assertSent(InlineLettrMailable::class);
});

it('converts Arrayable to array before passing to InlineLettrMailable', function () {
    Mail::fake();

    $dto = new class implements \Illuminate\Contracts\Support\Arrayable
    {
        public function toArray(): array
        {
            return ['custom_key' => 'custom_value', 'count' => 42];
        }
    };

    Mail::lettr()
        ->to('test@example.com')
        ->sendTemplate('data-template', substitutionData: $dto);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        // Verify the mailable was created with the correct substitution data
        $reflection = new \ReflectionClass($mailable);
        $property = $reflection->getProperty('substitutionData');
        $property->setAccessible(true);
        $data = $property->getValue($mailable);

        return $data === ['custom_key' => 'custom_value', 'count' => 42];
    });
});

it('still accepts plain arrays in sendTemplate', function () {
    Mail::fake();

    Mail::lettr()
        ->to('test@example.com')
        ->sendTemplate('plain-array-template', substitutionData: ['key' => 'value']);

    Mail::assertSent(InlineLettrMailable::class, function ($mailable) {
        $reflection = new \ReflectionClass($mailable);
        $property = $reflection->getProperty('substitutionData');
        $property->setAccessible(true);
        $data = $property->getValue($mailable);

        return $data === ['key' => 'value'];
    });
});
