<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Тестовая Job-обертка для проксирования событий.
 * Соответствует паттерну, описанному в Advanced разделе README.
 */
class FireEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        private readonly string $eventName,
        private readonly array $payload,
    ) {
    }

    public function handle(): void
    {
        event(new $this->eventName(...$this->payload));
    }
}
