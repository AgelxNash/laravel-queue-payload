<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Событие таймаута ожидания ответа.
 */
class ResponseTimeout
{
    use Dispatchable;

    public function __construct(
        public readonly string $correlationId,
        public readonly string $queue,
        public readonly int $timeoutSeconds,
        public readonly float $timestamp,
    ) {
    }
}
