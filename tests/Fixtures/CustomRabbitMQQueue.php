<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use Illuminate\Support\Str;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class CustomRabbitMQQueue extends RabbitMQQueue
{
    protected function createObjectPayload($job, $queue): array
    {
        if (method_exists($job, 'getExternalPayload')) {
            return [
                'uuid' => (string) Str::uuid(),
                'job' => ExternalHandler::NAME,
                'data' => [
                    'type' => get_class($job),
                    'params' => $job->getExternalPayload(),
                ],
            ];
        }

        return parent::createObjectPayload($job, $queue);
    }
}
