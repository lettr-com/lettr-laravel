<?php

use Illuminate\Support\Facades\Mail;
use Lettr\Contracts\TransporterContract;
use Lettr\Laravel\Transport\LettrTransportFactory;
use Lettr\Lettr;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

it('can create lettr mail transport', function () {
    // Configure the lettr mailer in mail config
    config()->set('mail.mailers.lettr', [
        'transport' => 'lettr',
    ]);

    $transport = Mail::mailer('lettr')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(LettrTransportFactory::class);
});

it('does not pass subject to builder when subject is null and template slug is set', function () {
    $capturedData = null;

    $fakeTransporter = new class($capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->captured = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
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
            $this->captured = $data;

            return ['request_id' => 'test-id'];
        }

        public function patch(string $uri, array $data): array
        {
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text('Fallback content');
    $email->getHeaders()->addTextHeader('X-Lettr-Template-Slug', 'welcome-email');
    // No subject set — $email->getSubject() returns null

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedData)->not->toHaveKey('subject')
        ->and($capturedData['template_slug'])->toBe('welcome-email');
});

it('passes subject to builder when subject is provided with template', function () {
    $capturedData = null;

    $fakeTransporter = new class($capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->captured = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
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
            $this->captured = $data;

            return ['request_id' => 'test-id'];
        }

        public function patch(string $uri, array $data): array
        {
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Override Subject')
        ->text('Fallback content');
    $email->getHeaders()->addTextHeader('X-Lettr-Template-Slug', 'welcome-email');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedData['subject'])->toBe('Override Subject')
        ->and($capturedData['template_slug'])->toBe('welcome-email');
});

it('forwards custom headers to the Lettr API', function () {
    $capturedData = null;

    $fakeTransporter = new class($capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->captured = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
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
            $this->captured = $data;

            return ['request_id' => 'test-id'];
        }

        public function patch(string $uri, array $data): array
        {
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->html('<p>Hello</p>');
    $email->getHeaders()->addTextHeader('X-Custom-Header', 'custom-value');
    $email->getHeaders()->addTextHeader('X-Another-Header', 'another-value');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedData['headers'])
        ->toHaveKey('X-Custom-Header', 'custom-value')
        ->toHaveKey('X-Another-Header', 'another-value');
});

it('does not forward internal X-Lettr headers as custom headers', function () {
    $capturedData = null;

    $fakeTransporter = new class($capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->captured = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
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
            $this->captured = $data;

            return ['request_id' => 'test-id'];
        }

        public function patch(string $uri, array $data): array
        {
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->html('<p>Hello</p>');
    $email->getHeaders()->addTextHeader('X-Lettr-Template-Slug', 'welcome-email');
    $email->getHeaders()->addTextHeader('X-Lettr-Tag', 'test-tag');
    $email->getHeaders()->addTextHeader('X-Custom-Header', 'custom-value');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedData['headers'])
        ->toHaveKey('X-Custom-Header', 'custom-value')
        ->not->toHaveKey('X-Lettr-Template-Slug')
        ->not->toHaveKey('X-Lettr-Tag');
});

it('does not forward standard email headers as custom headers', function () {
    $capturedData = null;

    $fakeTransporter = new class($capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->captured = $data;

            return ['request_id' => 'test-id', 'accepted' => 1, 'rejected' => 0];
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
            $this->captured = $data;

            return ['request_id' => 'test-id'];
        }

        public function patch(string $uri, array $data): array
        {
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->html('<p>Hello</p>');
    $email->getHeaders()->addTextHeader('X-Custom-Header', 'custom-value');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedData['headers'])
        ->toHaveKey('X-Custom-Header', 'custom-value')
        ->not->toHaveKey('From')
        ->not->toHaveKey('from')
        ->not->toHaveKey('To')
        ->not->toHaveKey('to')
        ->not->toHaveKey('Subject')
        ->not->toHaveKey('subject')
        ->not->toHaveKey('MIME-Version')
        ->not->toHaveKey('Date')
        ->not->toHaveKey('Content-Type')
        ->not->toHaveKey('Message-ID');
});

it('routes scheduled emails to the /emails/scheduled endpoint when X-Lettr-Scheduled-At is set', function () {
    $capturedUri = null;
    $capturedData = null;

    $fakeTransporter = new class($capturedUri, $capturedData) implements TransporterContract
    {
        public function __construct(public mixed &$uri, public mixed &$captured) {}

        public function post(string $uri, array $data): array
        {
            $this->uri = $uri;
            $this->captured = $data;

            // The shape POST /emails/scheduled actually returns. From
            // lettr-php 2.8.0 the response is parsed into a ScheduledEmail,
            // which needs `state`, `scheduled_at` and `from`; earlier versions
            // read only request_id/accepted/rejected and ignore the rest, so
            // this fake satisfies both.
            return [
                'request_id' => 'sch_01JQZ3N2K8XW9V6M4TBRC7YHDE',
                'transmission_id' => null,
                'state' => 'scheduled',
                'scheduled_at' => '2030-01-01T12:00:00Z',
                'from' => 'sender@example.com',
                'from_name' => null,
                'subject' => 'Scheduled',
                'recipients' => ['recipient@example.com'],
                'num_recipients' => 1,
                'accepted' => 1,
                'rejected' => 0,
                'tag' => null,
                'failure_reason' => null,
                'events' => [],
            ];
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
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Scheduled')
        ->html('<p>Hello</p>');
    $email->getHeaders()->addTextHeader('X-Lettr-Scheduled-At', '2030-01-01T12:00:00+00:00');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedUri)->toBe('emails/scheduled')
        ->and($capturedData['scheduled_at'])->toBe('2030-01-01T12:00:00+00:00')
        ->and($capturedData['headers'] ?? [])->not->toHaveKey('X-Lettr-Scheduled-At');
});

it('uses the /emails endpoint when X-Lettr-Scheduled-At is absent', function () {
    $capturedUri = null;

    $fakeTransporter = new class($capturedUri) implements TransporterContract
    {
        public function __construct(public mixed &$uri) {}

        public function post(string $uri, array $data): array
        {
            $this->uri = $uri;

            return ['request_id' => 'immediate-id', 'accepted' => 1, 'rejected' => 0];
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
            return ['request_id' => 'test-id'];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
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

    $lettr = new Lettr($fakeTransporter);
    $transport = new LettrTransportFactory($lettr);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Immediate')
        ->html('<p>Hello</p>');

    $envelope = new Envelope(
        new Address('sender@example.com'),
        [new Address('recipient@example.com')]
    );

    $sentMessage = new SentMessage($email, $envelope);

    $reflection = new ReflectionMethod($transport, 'doSend');
    $reflection->setAccessible(true);
    $reflection->invoke($transport, $sentMessage);

    expect($capturedUri)->toBe('emails');
});
