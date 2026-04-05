<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Событие открытия Circuit Breaker.
 */
class CircuitBreakerOpened
{
    use Dispatchable;

    public function __construct(
        public readonly int $failureCount,
        public readonly int $failureThreshold,
        public readonly int $retryAfterSeconds,
        public readonly float $timestamp,
    ) {
    }
}
