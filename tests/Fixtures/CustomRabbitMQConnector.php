<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Events\WorkerStopping;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connection\ConnectionFactory;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;

class CustomRabbitMQConnector extends RabbitMQConnector
{
    public function connect(array $config): Queue
    {
        $connection = ConnectionFactory::make($config);

        $queueConfig = \VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfigFactory::make($config);
        $queue = new CustomRabbitMQQueue($queueConfig);
        $queue->setConnection($connection);

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->close();
        });

        return $queue;
    }
}
