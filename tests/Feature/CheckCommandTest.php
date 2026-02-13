<?php

use Lettr\Contracts\TransporterContract;
use Lettr\Exceptions\UnauthorizedException;
use Lettr\Laravel\Console\CheckCommand;
use Lettr\Laravel\LettrManager;
use Lettr\Lettr;

beforeEach(function () {
    $this->transporter = Mockery::mock(TransporterContract::class);

    $lettr = new Lettr($this->transporter);
    $lettrManager = new LettrManager(fn () => $lettr);

    $this->app->instance(LettrManager::class, $lettrManager);
    $this->app->instance('lettr', $lettrManager);
});

function authCheckResponse(int $teamId = 42): array
{
    return ['team_id' => $teamId, 'timestamp' => '2025-01-01T00:00:00+00:00'];
}

function domainsResponse(array $domains): array
{
    return ['domains' => $domains];
}

function domainEntry(string $domain, bool $canSend = true, string $status = 'approved'): array
{
    return [
        'domain' => $domain,
        'status' => $status,
        'can_send' => $canSend,
        'dkim_status' => 'valid',
        'return_path_status' => 'valid',
        'created_at' => '2025-01-01T00:00:00+00:00',
        'verified_at' => $canSend ? '2025-01-01T00:00:00+00:00' : null,
    ];
}

it('passes all checks when everything is configured correctly', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse(42));

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andReturn(domainsResponse([domainEntry('example.com')]));

    $this->artisan(CheckCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('lettr (default)')
        ->expectsOutputToContain('valid (team #42)')
        ->expectsOutputToContain('noreply@example.com');
});

it('fails when mailer is not registered', function () {
    config()->set('mail.mailers.lettr', null);
    config()->set('mail.default', 'smtp');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andReturn(domainsResponse([domainEntry('example.com')]));

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('not registered');
});

it('fails when mailer is not the default', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'smtp');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andReturn(domainsResponse([domainEntry('example.com')]));

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('default is "smtp"');
});

it('fails when API key is missing', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', null);
    config()->set('mail.from.address', 'noreply@example.com');

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('not set')
        ->expectsOutputToContain('fix API key first');
});

it('fails when API key is invalid', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'invalid-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andThrow(new UnauthorizedException);

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('invalid or expired')
        ->expectsOutputToContain('fix API key first');
});

it('fails when from address is not set', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', null);

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('not configured');
});

it('fails when domain is not found in account', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@unknown.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andReturn(domainsResponse([domainEntry('example.com')]));

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('domain not found');
});

it('fails when domain is not verified', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andReturn(domainsResponse([domainEntry('example.com', canSend: false, status: 'pending')]));

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('domain not verified')
        ->expectsOutputToContain('Complete DNS verification');
});

it('handles API error during domain check gracefully', function () {
    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.default', 'lettr');
    config()->set('lettr.api_key', 'test-api-key');
    config()->set('mail.from.address', 'noreply@example.com');

    $this->transporter->shouldReceive('get')
        ->with('auth/check')
        ->andReturn(authCheckResponse());

    $this->transporter->shouldReceive('get')
        ->with('domains')
        ->andThrow(new \Lettr\Exceptions\ApiException('Connection error'));

    $this->artisan(CheckCommand::class)
        ->assertFailed()
        ->expectsOutputToContain('unable to reach Lettr API');
});
