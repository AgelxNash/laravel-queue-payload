<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckUserTariffJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(private readonly int $userId)
    {
    }

    public function getExternalPayload(): array
    {
        return [
            'userId' => $this->userId,
        ];
    }
}
