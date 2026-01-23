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
        ->sendTemplate('welcome-email', ['name' => 'John']);

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
        ->sendTemplate('order-confirmation', ['order_id' => 123]);

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
        ->sendTemplate('test-template', ['foo' => 'bar']);

    Mail::assertSent(InlineLettrMailable::class);
});
