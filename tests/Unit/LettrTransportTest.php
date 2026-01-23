<?php

use Illuminate\Support\Facades\Mail;
use Lettr\Laravel\Transport\LettrTransportFactory;

it('can create lettr mail transport', function () {
    // Configure the lettr mailer in mail config
    config()->set('mail.mailers.lettr', [
        'transport' => 'lettr',
    ]);

    $transport = Mail::mailer('lettr')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(LettrTransportFactory::class);
});
