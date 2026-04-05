<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Feature;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;
use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CustomPayloadJobFixture;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Проверяет переопределение createPayload() для полностью кастомной структуры JSON.
 *
 * Сценарий из README Advanced раздел 3: "Можно ли вообще избавиться от ключа 'job'?"
 *
 * CustomPayloadJobFixture использует ключ 'task' вместо 'job' и 'payload' вместо 'data'
 */
class CustomPayloadJobTest extends TestCase
{
    private RabbitMQQueue $connectMock;
    private ResponseWorkerInterface $workerMock;
    private CustomPayloadJobFixture $customJob;

    protected function setUp(): void
    {
        parent::setUp();

        $configMock = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig::class);
        $configMock->method('isDispatchAfterCommit')->willReturn(false);

        $this->connectMock = $this->getMockBuilder(RabbitMQQueue::class)
            ->setConstructorArgs([$configMock])
            ->onlyMethods(['pushRaw'])
            ->getMock();

        $this->workerMock = $this->createMock(ResponseWorkerInterface::class);
        $this->workerMock->method('queueName')->willReturn('billing-service:response');

        $this->customJob = new CustomPayloadJobFixture($this->connectMock, $this->workerMock, new HmacSigner(''));
    }

    /** Кастомный createPayload использует ключ 'task' вместо 'job' */
    public function testCustomPayloadUsesTaskKeyInsteadOfJob(): void
    {
        $captured = null;

        $this->connectMock
            ->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->customJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertArrayNotHasKey('job', $captured);
        $this->assertArrayHasKey('task', $captured);
    }

    /** Кастомный createPayload использует ключ 'payload' вместо 'data' */
    public function testCustomPayloadUsesPayloadKeyInsteadOfData(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->customJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertArrayNotHasKey('data', $captured);
        $this->assertArrayHasKey('payload', $captured);
        $this->assertSame(['userId' => 12345], $captured['payload']);
    }

    /** Ключ 'task' содержит значение handler из ExternalMessage */
    public function testCustomPayloadTaskKeyEqualsHandler(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->customJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', [], 'my-go-handler'),
            'billing-service:request'
        );

        $this->assertSame('my-go-handler', $captured['task']);
    }
}
