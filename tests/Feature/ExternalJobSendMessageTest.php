<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Feature;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;
use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Проверяет корректное формирование JSON payload при вызове ExternalJob::sendMessage()
 *
 * Сценарий из README раздел 2: "Отправка сообщения БЕЗ ожидания ответа (Fire and Forget)"
 */
class ExternalJobSendMessageTest extends TestCase
{
    private RabbitMQQueue $connectMock;
    private ResponseWorkerInterface $workerMock;
    private ExternalJob $externalJob;

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

        $this->externalJob = new ExternalJob($this->connectMock, $this->workerMock, new HmacSigner(''));
    }

    /** Payload содержит все обязательные ключи верхнего уровня */
    public function testPayloadHasRequiredTopLevelKeys(): void
    {
        $captured = null;

        $this->connectMock
            ->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertArrayHasKey('uuid', $captured);
        $this->assertArrayHasKey('id', $captured);
        $this->assertArrayHasKey('job', $captured);
        $this->assertArrayHasKey('data', $captured);
    }

    /** Ключ job == 'external' по умолчанию */
    public function testPayloadJobKeyIsExternalByDefault(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertSame(ExternalHandler::NAME, $captured['job']);
    }

    /** data.type содержит имя переданного алиаса */
    public function testPayloadDataTypeEqualsMessageName(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertSame('TASK_CHECK_TARIFF', $captured['data'][ExternalJob::JOB_CLASS]);
    }

    /** data.params содержит переданные параметры */
    public function testPayloadDataParamsEqualsMessageParams(): void
    {
        $params = ['userId' => 12345, 'extra' => 'value'];
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', $params),
            'billing-service:request'
        );

        $this->assertSame($params, $captured['data'][ExternalJob::JOB_PARAMS]);
    }

    /** Без correlationId поле data.response = null */
    public function testPayloadResponseIsNullWithoutCorrelationId(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request'
        );

        $this->assertNull($captured['data'][ExternalJob::JOB_RESPONSE]);
    }

    /** С correlationId поле data.response = queueName воркера */
    public function testPayloadResponseIsWorkerQueueNameWhenCorrelationIdIsSet(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345]),
            'billing-service:request',
            'some-correlation-id'
        );

        $this->assertSame('billing-service:response', $captured['data'][ExternalJob::JOB_RESPONSE]);
    }

    /** Кастомный handler переопределяет ключ 'job' */
    public function testCustomHandlerOverridesJobKey(): void
    {
        $captured = null;

        $this->connectMock
            ->method('pushRaw')
            ->willReturnCallback(static function (string $payload) use (&$captured) {
                $captured = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return null;
            });

        $this->externalJob->sendMessage(
            new ExternalMessage('TASK_CHECK_TARIFF', ['userId' => 12345], 'go-billing-handler'),
            'billing-service:request'
        );

        $this->assertSame('go-billing-handler', $captured['job']);
    }
}
