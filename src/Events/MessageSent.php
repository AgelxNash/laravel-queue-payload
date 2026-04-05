<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Событие отправки сообщения в очередь.
 */
class MessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly string $queue,
        public readonly string $type,
        public readonly string|null $correlationId,
        /** @var array<string, mixed> */
        /** @var array<string, mixed> */
        public readonly array $params,
        public readonly float $timestamp,
    ) {
    }
}
