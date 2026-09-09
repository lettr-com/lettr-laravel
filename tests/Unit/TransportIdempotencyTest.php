<?php

declare(strict_types=1);

use Lettr\Contracts\SupportsRequestHeaders;
use Lettr\Contracts\TransporterContract;
use Lettr\Laravel\Support\CurrentQueueJob;
use Lettr\Laravel\Support\IdempotencyKeyGenerator;
use Lettr\Laravel\Transport\LettrTransportFactory;
use Lettr\Lettr;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A transporter that can carry headers, so the Idempotency-Key is observable.
 * The fakes in LettrTransportTest deliberately do not implement
 * SupportsRequestHeaders, which is what makes them a useful check that a
 * header-less transporter still sends.
 */
function headerCapturingTransporter(): object
{
    return new class implements SupportsRequestHeaders, TransporterContract
    {
        /** @var array<string, string> */
        public array $lastHeaders = [];

        public bool $posted = false;

        public function postWithHeaders(string $uri, array $data, array $headers): array
        {
            $this->lastHeaders = $headers;

            return $this->post($uri, $data);
        }

        public function post(string $uri, array $data): array
        {
            $this->posted = true;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
        {
            return [];
        }

        public function get(string $uri): array
        {
            return [];
        }

        public function getWithQuery(string $uri, array $query = []): array
        {
            return [];
        }

        public function put(string $uri, array $data): array
        {
            return [];
        }

        public function patch(string $uri, array $data): array
        {
            return [];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function lastResponseHeaders(): array
        {
            return [];
        }

        public function lastStatusCode(): ?int
        {
            return null;
        }
    };
}

/**
 * @param  array<string, string>  $headers
 */
function sendThroughTransport(object $transporter, array $headers = [], ?string $jobUuid = 'job-uuid-1', bool $enabled = true): void
{
    $currentJob = new CurrentQueueJob;
    $currentJob->set($jobUuid);

    $transport = new LettrTransportFactory(
        new Lettr($transporter),
        [],
        new IdempotencyKeyGenerator($currentJob),
        $enabled,
    );

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Hello')
        ->html('<p>Hi</p>');

    foreach ($headers as $name => $value) {
        $email->getHeaders()->addTextHeader($name, $value);
    }

    $message = new SentMessage($email, new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')],
    ));

    $method = new ReflectionMethod($transport, 'doSend');
    $method->invoke($transport, $message);
}

test('a send inside a queue job carries a generated key', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter);

    expect($transporter->lastHeaders)->toHaveKey('Idempotency-Key')
        ->and($transporter->lastHeaders['Idempotency-Key'])->toMatch('/\A[A-Za-z0-9._-]{1,255}\z/');
});

/**
 * A retried HTTP request is a new process, so nothing survives to key on.
 * Silence is more honest than a key that changes every attempt.
 */
test('a synchronous send carries no key', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, jobUuid: null);

    expect($transporter->lastHeaders)->toBe([])
        ->and($transporter->posted)->toBeTrue();
});

test('an explicit key wins over the generated one', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, ['X-Lettr-Idempotency-Key' => 'order-12345']);

    expect($transporter->lastHeaders)->toBe(['Idempotency-Key' => 'order-12345']);
});

test('an explicit key works outside a queue job too', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, ['X-Lettr-Idempotency-Key' => 'order-12345'], jobUuid: null);

    expect($transporter->lastHeaders)->toBe(['Idempotency-Key' => 'order-12345']);
});

/**
 * The deliberate-resend escape hatch: send this again, on purpose, even though
 * it is byte-identical to something this job already sent.
 */
test('opting out beats both the explicit and the generated key', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, [
        'X-Lettr-Idempotency' => 'disabled',
        'X-Lettr-Idempotency-Key' => 'order-12345',
    ]);

    expect($transporter->lastHeaders)->toBe([])
        ->and($transporter->posted)->toBeTrue();
});

test('the config flag turns the default off without touching call sites', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, enabled: false);

    expect($transporter->lastHeaders)->toBe([]);
});

test('the config flag does not silence an explicit key', function (): void {
    $transporter = headerCapturingTransporter();

    sendThroughTransport($transporter, ['X-Lettr-Idempotency-Key' => 'order-12345'], enabled: false);

    // Turning the *default* off is not the same as refusing a key the caller
    // deliberately asked for.
    expect($transporter->lastHeaders)->toBe(['Idempotency-Key' => 'order-12345']);
});

test('the internal headers are not forwarded to the API as custom headers', function (): void {
    $transporter = new class extends stdClass implements SupportsRequestHeaders, TransporterContract
    {
        /** @var array<string, mixed>|null */
        public ?array $lastData = null;

        public function postWithHeaders(string $uri, array $data, array $headers): array
        {
            return $this->post($uri, $data);
        }

        public function post(string $uri, array $data): array
        {
            $this->lastData = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
        {
            return [];
        }

        public function get(string $uri): array
        {
            return [];
        }

        public function getWithQuery(string $uri, array $query = []): array
        {
            return [];
        }

        public function put(string $uri, array $data): array
        {
            return [];
        }

        public function patch(string $uri, array $data): array
        {
            return [];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function lastResponseHeaders(): array
        {
            return [];
        }

        public function lastStatusCode(): ?int
        {
            return null;
        }
    };

    sendThroughTransport($transporter, [
        'X-Lettr-Idempotency-Key' => 'order-12345',
    ]);

    expect($transporter->lastData['headers'] ?? [])->not->toHaveKey('X-Lettr-Idempotency-Key');
});
