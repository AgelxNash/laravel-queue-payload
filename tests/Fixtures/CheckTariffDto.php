<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\DtoInterface;

class CheckTariffDto implements DtoInterface
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $region = null,
    ) {
    }
}
