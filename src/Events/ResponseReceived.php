<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Событие получения ответа из очереди.
 */
class ResponseReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $correlationId,
        public readonly string $queue,
        public readonly mixed $response,
        public readonly float $waitTime,
        public readonly float $timestamp,
    ) {
    }
}
