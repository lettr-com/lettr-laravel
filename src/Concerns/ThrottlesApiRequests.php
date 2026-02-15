<?php

declare(strict_types=1);

namespace Lettr\Laravel\Concerns;

use Closure;
use Lettr\Exceptions\RateLimitException;

/**
 * Provides rate-limit-aware API request handling for console commands.
 *
 * Catches RateLimitException and retries after the Retry-After delay
 * specified in the API response headers.
 */
trait ThrottlesApiRequests
{
    /**
     * Execute a callback with automatic rate limit retry.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws RateLimitException When max retries are exhausted
     */
    protected function withRateLimitRetry(Closure $callback, int $maxRetries = 3): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (RateLimitException $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                $waitSeconds = $e->retryAfter ?? 1;
                sleep($waitSeconds);
            }
        }
    }
}
