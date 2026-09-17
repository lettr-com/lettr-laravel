<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

/**
 * The uuid of the queue job currently being processed, if any.
 *
 * This is what makes a default idempotency key possible. A retried job carries
 * the *same* uuid - Laravel re-pushes the identical payload on an automatic
 * release, and `queue:retry` only resets `attempts` and `retry_until` - so it is
 * stable across exactly the attempts we want to deduplicate, and different for
 * every fresh dispatch.
 *
 * Nothing else in the request survives a retry. The Symfony `Message-ID` looks
 * tempting but is generated lazily at send time, so it is freshly random on
 * every attempt.
 *
 * Registered as a scoped binding and cleared when the job finishes, so nothing
 * leaks between jobs on a long-running worker.
 */
final class CurrentQueueJob
{
    private ?string $uuid = null;

    public function set(?string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function forget(): void
    {
        $this->uuid = null;
    }

    /**
     * The current job's uuid, or null when not running inside a queue job.
     */
    public function uuid(): ?string
    {
        return $this->uuid;
    }
}
