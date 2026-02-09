<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Lettr\Laravel\Console\InitCommand;
use Lettr\Laravel\LettrManager;

function apiKeyQuestion(): string
{
    return 'Enter your Lettr API key';
}

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->app->instance(Filesystem::class, $this->filesystem);

    // Mock the LettrManager to prevent API key validation during tests
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->app->instance(LettrManager::class, $this->lettrManager);
    $this->app->instance('lettr', $this->lettrManager);

    // Set an empty string to simulate no API key (but not null to avoid service provider issues)
    config()->set('lettr.api_key', '');

    // Mock HTTP facade to prevent real async requests in startAsyncFetches
    \Illuminate\Support\Facades\Http::fake([
        'https://app.lettr.com/api/auth/check' => \Illuminate\Support\Facades\Http::response(['team_id' => 1], 200),
        '*' => \Illuminate\Support\Facades\Http::response(['data' => ['domains' => [], 'templates' => []]], 200),
    ]);
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
        ->expectsQuestion(apiKeyQuestion(), 'test_api_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
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

    // No .env write expected since user chose not to replace API key and not to set MAIL_MAILER
    $this->filesystem
        ->shouldNotReceive('put')
        ->with($envPath, Mockery::any());

    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
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
        ->expectsQuestion(apiKeyQuestion(), 'test_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
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
        ->expectsQuestion(apiKeyQuestion(), 'test_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
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
        ->expectsQuestion(apiKeyQuestion(), 'new_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
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
        ->expectsQuestion(apiKeyQuestion(), 'new_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('fails when api key is not provided', function () {
    $this->artisan(InitCommand::class)
        ->expectsQuestion(apiKeyQuestion(), '')
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

    // No .env write expected since nothing changed
    $this->filesystem
        ->shouldNotReceive('put')
        ->with($envPath, Mockery::any());

    // MAIL_MAILER is asked early with other config settings
    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'yes')
        ->expectsConfirmation('Do you want to keep your templates in the codebase?', 'yes')
        ->assertSuccessful();
});

it('skips mail mailer prompt when already set to lettr', function () {
    config()->set('lettr.api_key', 'test_key');
    config()->set('mail.default', 'lettr');

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
        ->andReturn("LETTR_API_KEY=test_key\nMAIL_MAILER=lettr\n");

    // No .env write expected since nothing changed
    $this->filesystem
        ->shouldNotReceive('put')
        ->with($envPath, Mockery::any());

    // MAIL_MAILER prompt should be skipped since it's already 'lettr'
    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        // No MAIL_MAILER confirmation here - it should be skipped
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('validates existing api key and warns if invalid', function () {
    config()->set('lettr.api_key', 'invalid_key');

    // Clear and override HTTP fake for this test - invalid_key gets 401, valid_key gets 200
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'https://app.lettr.com/api/auth/check' => function ($request) {
            // Check the token in the authorization header
            $authHeader = $request->header('Authorization');
            if (is_array($authHeader)) {
                $authHeader = $authHeader[0] ?? '';
            }

            if (str_contains($authHeader ?? '', 'invalid_key')) {
                return Http::response(['error' => 'Unauthorized'], 401);
            }

            return Http::response(['team_id' => 1], 200);
        },
        'https://app.lettr.com/api/domains' => Http::response(['data' => ['domains' => []]], 200),
        'https://app.lettr.com/api/templates*' => Http::response(['data' => ['templates' => []]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

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
        ->andReturn("LETTR_API_KEY=invalid_key\n");

    $this->filesystem
        ->shouldReceive('put')
        ->with($envPath, Mockery::on(fn ($content) => str_contains($content, 'LETTR_API_KEY=valid_key')))
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsOutputToContain('The configured API key is invalid')
        ->expectsQuestion(apiKeyQuestion(), 'valid_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('validates newly entered api key and prompts again if invalid', function () {
    // Clear and override HTTP fake for this test - invalid_key gets 401, valid_key gets 200
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'https://app.lettr.com/api/auth/check' => function ($request) {
            // Check the token in the authorization header
            $authHeader = $request->header('Authorization');
            if (is_array($authHeader)) {
                $authHeader = $authHeader[0] ?? '';
            }

            if (str_contains($authHeader ?? '', 'invalid_key')) {
                return Http::response(['error' => 'Unauthorized'], 401);
            }

            return Http::response(['team_id' => 1], 200);
        },
        'https://app.lettr.com/api/domains' => Http::response(['data' => ['domains' => []]], 200),
        'https://app.lettr.com/api/templates*' => Http::response(['data' => ['templates' => []]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

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
        ->with($envPath, Mockery::on(fn ($content) => str_contains($content, 'LETTR_API_KEY=valid_key')))
        ->once();

    $this->artisan(InitCommand::class)
        ->expectsQuestion(apiKeyQuestion(), 'invalid_key')
        ->expectsOutputToContain('Invalid API key')
        ->expectsQuestion(apiKeyQuestion(), 'valid_key')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});

it('proceeds normally with valid existing api key', function () {
    config()->set('lettr.api_key', 'valid_key');

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
        ->andReturn("LETTR_API_KEY=valid_key\n");

    // No .env write expected since user chose not to replace API key
    $this->filesystem
        ->shouldNotReceive('put')
        ->with($envPath, Mockery::any());

    $this->artisan(InitCommand::class)
        ->expectsConfirmation('An API key is already configured. Do you want to replace it?', 'no')
        ->expectsConfirmation('config/lettr.php already exists. Overwrite it?', 'no')
        ->expectsConfirmation('Set MAIL_MAILER=lettr in your .env?', 'no')
        ->expectsConfirmation('Do you have existing email templates in your codebase?', 'no')
        ->expectsConfirmation('Do you want to download templates from your Lettr account?', 'no')
        ->expectsQuestion('Which type-safe classes do you want to generate?', [])
        ->assertSuccessful();
});
