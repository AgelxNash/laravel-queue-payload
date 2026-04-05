<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

class TariffUpgradedEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $tariff,
    ) {
    }
}
