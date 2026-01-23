<?php

namespace Lettr\Laravel\Tests;

use Lettr\Laravel\LettrServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LettrServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('lettr.api_key', 'test-api-key');
    }
}
