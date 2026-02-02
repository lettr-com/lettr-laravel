<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Laravel\Console\InitCommand;
use Lettr\Laravel\LettrManager;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->app->instance(Filesystem::class, $this->filesystem);

    // Mock the LettrManager to prevent API key validation during tests
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->app->instance(LettrManager::class, $this->lettrManager);
    $this->app->instance('lettr', $this->lettrManager);

    // Set an empty string to simulate no API key (but not null to avoid service provider issues)
    config()->set('lettr.api_key', '');
});

it('completes setup when user has no templates and skips download', function () {
    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(false);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn("<?php\n\nreturn [\n    'mailers' => [\n    ],\n];");

    $this->filesystem
        ->shouldReceive('put')
        ->with($mailConfigPath, Mockery::any())
        ->once();

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("APP_NAME=Laravel\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::on(fn ($content) => str_contains($content, 'LETTR_API_KEY=test_api_key')))
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', 'test_api_key')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('asks to replace existing api key', function () {
    config()->set('lettr.api_key', 'existing_key');

    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn("<?php\n\nreturn [\n    'mailers' => [\n        'lettr' => ['transport' => 'lettr'],\n    ],\n];");

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("LETTR_API_KEY=existing_key\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::any())
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('adds lettr mailer to mail config', function () {
    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $originalMailConfig = <<<'PHP'
<?php

return [
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
        ],
    ],
];
PHP;

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(false);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn($originalMailConfig);

    $this->filesystem
        ->shouldReceive('put')
        ->with($mailConfigPath, Mockery::on(fn ($content) => str_contains($content, "'lettr' =>") && str_contains($content, "'transport' => 'lettr'")))
        ->once();

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("APP_NAME=Laravel\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::any())
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', 'test_key')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('skips mailer setup if already configured', function () {
    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $mailConfigWithLettr = <<<'PHP'
<?php

return [
    'mailers' => [
        'lettr' => [
            'transport' => 'lettr',
        ],
    ],
];
PHP;

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(false);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn($mailConfigWithLettr);

    // Should not write to mail config since lettr is already there
    $this->filesystem
        ->shouldNotReceive('put')
        ->with($mailConfigPath, Mockery::any());

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("APP_NAME=Laravel\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::any())
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', 'test_key')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('creates env file from example if it does not exist', function () {
    $envPath = base_path('.env');
    $envExamplePath = base_path('.env.example');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(false);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn("<?php\n\nreturn [\n    'mailers' => [\n        'lettr' => [],\n    ],\n];");

    // .env does not exist
    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(false);

    // .env.example exists
    $this->filesystem
        ->shouldReceive('exists')
        ->with($envExamplePath)
        ->andReturn(true);

    // Copy .env.example to .env
    $this->filesystem
        ->shouldReceive('copy')
        ->with($envExamplePath, $envPath)
        ->once();

    // Read the new .env file
    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("APP_NAME=Laravel\n");

    // Write the API key
    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::any())
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', 'new_key')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('replaces existing api key in env file', function () {
    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(false);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn("<?php\n\nreturn [\n    'mailers' => [\n        'lettr' => [],\n    ],\n];");

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("APP_NAME=Laravel\nLETTR_API_KEY=old_key\nAPP_DEBUG=true\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::on(function ($content) {
            return str_contains($content, 'LETTR_API_KEY=new_key')
                && ! str_contains($content, 'LETTR_API_KEY=old_key');
        }))
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', 'new_key')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('fails when api key is not provided', function () {
    $this->artisan(InitCommand::class)
        ->expectsQuestion('Enter your Lettr API key', '')
        ->assertFailed();
});

it('skips type generation when user keeps existing templates locally', function () {
    config()->set('lettr.api_key', 'test_key');

    $envPath = base_path('.env');
    $configPath = config_path('lettr.php');
    $mailConfigPath = config_path('mail.php');

    $this->filesystem
        ->shouldReceive('exists')
        ->with($configPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('exists')
        ->with($mailConfigPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($mailConfigPath)
        ->andReturn("<?php\n\nreturn [\n    'mailers' => [\n        'lettr' => [],\n    ],\n];");

    $this->filesystem
        ->shouldReceive('exists')
        ->with($envPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('get')
        ->with($envPath)
        ->andReturn("LETTR_API_KEY=test_key\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::any())
        ->once();

    // When user keeps templates locally, they get asked if they want to set MAIL_MAILER=lettr
    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'yes')
        ->expectsConfirmation('Do you want to keep your templates in the codebase?', 'yes')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->assertSuccessful();
});
