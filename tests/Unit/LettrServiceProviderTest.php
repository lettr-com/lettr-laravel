<?php

use Lettr\Laravel\Facades\Lettr;
use Lettr\Lettr as LettrClient;

it('can resolve lettr client from container', function () {
    $client = app('lettr');

    expect($client)->toBeInstanceOf(LettrClient::class);
});

it('can use lettr facade', function () {
    expect(Lettr::getFacadeRoot())->toBeInstanceOf(LettrClient::class);
});

it('throws exception when api key is missing', function () {
    config()->set('lettr.api_key', null);
    config()->set('services.lettr.key', null);

    app('lettr');
})->throws(\Lettr\Laravel\Exceptions\ApiKeyIsMissing::class);
