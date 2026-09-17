<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Lettr\Dto\Email\SendEmailData;
use Lettr\ValueObjects\IdempotencyKey;

/**
 * Derives the default idempotency key for a queued send.
 *
 * The key combines the queue job's uuid with a hash of the payload, and needs
 * both halves:
 *
 * - **The job uuid** is what survives a retry, and what differs between two
 *   deliberate sends. Without it, two identical emails sent on purpose a minute
 *   apart would collapse into one.
 * - **The payload hash** is what keeps a job that sends several emails working.
 *   A job looping over recipients would otherwise reuse one key for different
 *   payloads and get a 409 on the second email - working code broken by an
 *   upgrade.
 *
 * Outside a queue job there is nothing stable to key on: a retried HTTP request
 * is a new process. Those sends get no key unless the caller supplies one.
 */
final readonly class IdempotencyKeyGenerator
{
    public function __construct(
        private CurrentQueueJob $currentQueueJob,
    ) {}

    /**
     * The key for this send, or null when there is nothing safe to derive one from.
     */
    public function for(SendEmailData $data): ?IdempotencyKey
    {
        $jobUuid = $this->currentQueueJob->uuid();

        if ($jobUuid === null) {
            return null;
        }

        return new IdempotencyKey(hash('sha256', $jobUuid.'|'.json_encode($data->toArray())));
    }
}
