<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Integration\Fixtures;

use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\ResponseMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Integration-тест fixture: job с handle() и reply() для реального RPC-цикла.
 */
class IntegrationCheckUserTariffJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
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

    public function handle(ExternalJob $externalJob, Job $job): void
    {
        $responseQueue = $job->payload()['data'][ExternalJob::JOB_RESPONSE] ?? null;

        if (!empty($responseQueue)) {
            $externalJob->sendMessage(
                message: new ResponseMessage(
                    success: true,
                    data: [
                        'userId' => $this->userId,
                        'tariff' => 'Premium',
                    ],
                    metadata: ['process_time' => 0.1],
                ),
                queue: $responseQueue,
                correlationId: $job->getJobId(),
            );
        }
    }
}
