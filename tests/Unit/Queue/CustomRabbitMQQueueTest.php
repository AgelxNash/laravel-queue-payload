<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CheckUserTariffJob;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CustomRabbitMQQueue;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;

class CustomRabbitMQQueueTest extends TestCase
{
    public function testSerializationViaDispatcher(): void
    {
        $configMock = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig::class);
        $configMock->method('isDispatchAfterCommit')->willReturn(false);

        $queue = $this->getMockBuilder(CustomRabbitMQQueue::class)
            ->setConstructorArgs([$configMock])
            ->onlyMethods(['pushRaw'])
            ->getMock();

        $queue->setContainer($this->app);

        $job = new CheckUserTariffJob(12345);

        $queue->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(function ($payload) use ($job) {
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                $this->assertArrayHasKey('uuid', $data);
                $this->assertSame(ExternalHandler::NAME, $data['job']);
                $this->assertSame(get_class($job), $data['data']['type']);
                $this->assertSame(['userId' => 12345], $data['data']['params']);

                return 'correlation-id-mock';
            });

        $queue->push($job);
    }
}
