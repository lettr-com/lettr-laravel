<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\Job as BaseJob;
use Lettr\Builders\EmailBuilder;
use Lettr\Dto\Email\SendEmailData;
use Lettr\Laravel\Support\CurrentQueueJob;
use Lettr\Laravel\Support\IdempotencyKeyGenerator;
use Lettr\ValueObjects\IdempotencyKey;

function emailPayload(string $to = 'recipient@example.com', string $subject = 'Hello'): SendEmailData
{
    return (new EmailBuilder)
        ->from('sender@example.com')
        ->to([$to])
        ->subject($subject)
        ->html('<p>Hi</p>')
        ->build();
}

function generatorForJob(?string $uuid): IdempotencyKeyGenerator
{
    $job = new CurrentQueueJob;
    $job->set($uuid);

    return new IdempotencyKeyGenerator($job);
}

// ---------------------------------------------------------------------------
// What the default key is derived from
// ---------------------------------------------------------------------------

/**
 * The failure this exists for: the API accepts the send, the response times
 * out, the job throws, the queue retries it. Laravel re-pushes the identical
 * payload - and `queue:retry` only resets `attempts` and `retry_until` - so the
 * uuid is the same and the key comes out the same.
 */
test('a retry of the same job produces the same key', function (): void {
    $first = generatorForJob('job-uuid-1')->for(emailPayload());
    $retry = generatorForJob('job-uuid-1')->for(emailPayload());

    expect($first?->value)->toBe($retry?->value);
});

test('a different dispatch produces a different key, so deliberate resends still send', function (): void {
    $first = generatorForJob('job-uuid-1')->for(emailPayload());
    $second = generatorForJob('job-uuid-2')->for(emailPayload());

    expect($first?->value)->not->toBe($second?->value);
});

/**
 * The reason the key is not the job uuid alone. A job looping over recipients
 * would otherwise reuse one key for different payloads and get a 409 on the
 * second email - working code broken by an upgrade.
 */
test('one job sending several different emails gets a key each', function (): void {
    $generator = generatorForJob('job-uuid-1');

    $keys = array_map(
        static fn (string $to): ?string => $generator->for(emailPayload($to))?->value,
        ['a@example.com', 'b@example.com', 'c@example.com'],
    );

    expect(array_unique($keys))->toHaveCount(3);
});

test('the subject is part of the payload, so two mails to one address differ', function (): void {
    $generator = generatorForJob('job-uuid-1');

    expect($generator->for(emailPayload(subject: 'First'))?->value)
        ->not->toBe($generator->for(emailPayload(subject: 'Second'))?->value);
});

/**
 * A retried HTTP request is a new process - nothing survives it, so there is
 * nothing honest to key on. Those sends stay unprotected unless the caller
 * supplies a key.
 */
test('outside a queue job there is no key', function (): void {
    expect(generatorForJob(null)->for(emailPayload()))->toBeNull();
});

test('the generated key satisfies the API format', function (): void {
    $key = generatorForJob('job-uuid-1')->for(emailPayload());

    expect($key)->toBeInstanceOf(IdempotencyKey::class)
        ->and($key?->value)->toMatch('/\A[A-Za-z0-9._-]{1,255}\z/');
});

/**
 * Laravel's own JobProcessing listeners call `payload()`, so a partial mock of
 * the Job interface blows up before our listener ever runs. A small fake keeps
 * the test about the tracker rather than about Mockery expectations.
 */
function fakeQueueJob(string $uuid): Job
{
    return new class($uuid) extends BaseJob implements Job
    {
        public function __construct(private readonly string $jobUuid)
        {
            $this->connectionName = 'redis';
        }

        public function uuid(): string
        {
            return $this->jobUuid;
        }

        public function payload(): array
        {
            return ['uuid' => $this->jobUuid];
        }

        public function getJobId(): string
        {
            return $this->jobUuid;
        }

        public function getRawBody(): string
        {
            return '{}';
        }

        public function attempts(): int
        {
            return 1;
        }
    };
}

// ---------------------------------------------------------------------------
// Tracking the current job
// ---------------------------------------------------------------------------

test('the current job uuid is set while a job runs and cleared afterwards', function (): void {
    $tracker = app(CurrentQueueJob::class);

    expect($tracker->uuid())->toBeNull();

    $job = fakeQueueJob('job-uuid-1');

    event(new JobProcessing('redis', $job));

    expect(app(CurrentQueueJob::class)->uuid())->toBe('job-uuid-1');

    event(new JobProcessed('redis', $job));

    // A worker is one long process. A uuid left behind would key the next
    // job's send to the previous job's identity.
    expect(app(CurrentQueueJob::class)->uuid())->toBeNull();
});

test('a failed job clears the current uuid too', function (): void {
    $job = fakeQueueJob('job-uuid-1');

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new RuntimeException('boom')));

    expect(app(CurrentQueueJob::class)->uuid())->toBeNull();
});
