<?php

use Lettr\Laravel\Facades\Lettr;
use Lettr\Laravel\LettrManager;
use Lettr\Lettr as LettrClient;

it('can resolve lettr manager from container', function () {
    $manager = app('lettr');

    expect($manager)->toBeInstanceOf(LettrManager::class);
});

it('can resolve raw lettr client from container', function () {
    $client = app(LettrClient::class);

    expect($client)->toBeInstanceOf(LettrClient::class);
});

it('can use lettr facade', function () {
    expect(Lettr::getFacadeRoot())->toBeInstanceOf(LettrManager::class);
});

it('throws exception when api key is missing', function () {
    config()->set('lettr.api_key', null);
    config()->set('services.lettr.key', null);

    // The exception is thrown when trying to use the SDK, not when resolving the manager
    app('lettr')->sdk();
})->throws(\Lettr\Laravel\Exceptions\ApiKeyIsMissing::class);
