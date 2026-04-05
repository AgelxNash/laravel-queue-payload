<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Events\MessageSent;
use AgelxNash\LaravelQueuePayload\Events\ResponseReceived;
use AgelxNash\LaravelQueuePayload\Events\ResponseTimeout;
use AgelxNash\LaravelQueuePayload\Exceptions\MaxAttemptsQueueException;
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CheckTariffDto;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Exception;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class ExternalJobTest extends TestCase
{
    private RabbitMQQueue $connectMock;
    private ResponseWorkerInterface $workerMock;
    private ExternalJob $externalJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectMock = $this->getMockBuilder(RabbitMQQueue::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushRaw', 'declareQueue', 'deleteQueue'])
            ->getMock();
        $this->workerMock = $this->createMock(ResponseWorkerInterface::class);
        $this->externalJob = new ExternalJob($this->connectMock, $this->workerMock, new HmacSigner(''));
    }

    public function testAddSubscriber(): void
    {
        $this->externalJob->addSubscriber('sub1');
        $this->externalJob->addSubscriber('sub2');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getHandler')->willReturn('handler');
        $message->method('getName')->willReturn('name');
        $message->method('getParams')->willReturn([]);

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->exactly(2))->method('pushRaw');

        $this->externalJob->sendEvent($message);
    }

    public function testGetResponse(): void
    {
        $message = $this->createMock(MessageInterface::class);
        $this->workerMock->expects($this->once())
            ->method('waitResponse')
            ->willReturn('response-data');

        $result = $this->externalJob->getResponse($message, 'test-queue');

        $this->assertSame('response-data', $result);
    }

    public function testGetResponseSharedModeUsesWorkerQueueName(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'shared');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(function (string $payload): string {
                $data = json_decode($payload, true);
                // В shared mode response queue = worker queueName
                $this->assertSame('shared-response-queue', $data['data']['response']);

                return 'job-id';
            });

        $this->workerMock->expects($this->once())
            ->method('waitResponse')
            ->with(
                $this->isType('string'),
                $this->callback(static fn ($p) => is_callable($p)),
                'shared-response-queue'
            )
            ->willReturn(['ok' => true]);

        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);
    }

    public function testGetResponsePerRequestModeCreatesTemporaryQueue(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'per_request');
        $this->app['config']->set('app.name', 'test-app');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');

        $declaredQueueName = null;

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->once())
            ->method('declareQueue')
            ->willReturnCallback(function (string $name, bool $durable, bool $autoDelete, array $arguments) use (&$declaredQueueName): void {
                $declaredQueueName = $name;
                $this->assertFalse($durable);
                $this->assertTrue($autoDelete);
                $this->assertArrayHasKey('x-expires', $arguments);
                $this->assertArrayHasKey('x-message-ttl', $arguments);
            });

        $connectMock->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(function (string $payload) use (&$declaredQueueName): string {
                $data = json_decode($payload, true);
                // В per_request mode response queue = временная очередь
                $this->assertStringContainsString('.rpc.reply.', $data['data']['response']);
                $declaredQueueName = $data['data']['response'];

                return 'job-id';
            });

        $connectMock->expects($this->once())
            ->method('deleteQueue')
            ->with($this->callback(static fn (string $name) => str_contains($name, '.rpc.reply.')));

        $this->workerMock->expects($this->once())
            ->method('waitResponse')
            ->with(
                $this->isType('string'),
                $this->callback(static fn ($p) => is_callable($p)),
                $this->callback(static fn (string $name) => str_contains($name, '.rpc.reply.'))
            )
            ->willReturn(['ok' => true]);

        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);
        $this->assertNotNull($declaredQueueName);
        $this->assertStringContainsString('test-app.rpc.reply.', (string) $declaredQueueName);
    }

    public function testGetResponseDirectReplyToFallbackToPerRequest(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'direct_reply_to');
        $this->app['config']->set('app.name', 'test-app');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->once())
            ->method('declareQueue')
            ->with(
                $this->callback(static fn (string $name) => str_contains($name, '.rpc.reply.')),
                false,
                true,
                $this->callback(static fn (array $args) => isset($args['x-expires']))
            );

        $connectMock->expects($this->once())
            ->method('deleteQueue')
            ->with($this->callback(static fn (string $name) => str_contains($name, '.rpc.reply.')));

        $this->workerMock->expects($this->once())
            ->method('waitResponse')
            ->with(
                $this->isType('string'),
                $this->callback(static fn ($p) => is_callable($p)),
                $this->callback(static fn (string $name) => str_contains($name, '.rpc.reply.'))
            )
            ->willReturn(['ok' => true]);

        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);
    }

    public function testGetResponseUnknownModeFallsBackToShared(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'unknown_mode');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        // В unknown mode declareQueue НЕ должен вызываться
        $connectMock->expects($this->never())->method('declareQueue');
        $connectMock->expects($this->never())->method('deleteQueue');

        $this->workerMock->expects($this->once())
            ->method('waitResponse')
            ->with(
                $this->isType('string'),
                $this->callback(static fn ($p) => is_callable($p)),
                'shared-response-queue'
            )
            ->willReturn(['ok' => true]);

        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);
    }

    public function testCreatePerRequestQueueThrowsWhenNotRabbitMqQueue(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'per_request');

        $nonRabbitMqConnect = $this->createMock(QueueContract::class);
        $externalJob = new ExternalJob($nonRabbitMqConnect, $this->workerMock, new HmacSigner(''));

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');
        $this->workerMock->method('waitResponse')->willReturn(['ok' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('per_request reply mode requires RabbitMQQueue driver');

        $externalJob->getResponse($message, 'target-queue');
    }

    public function testCleanupPerRequestQueueSwallowsExceptions(): void
    {
        $this->app['config']->set('agelxnash-queue.reply.mode', 'per_request');
        $this->app['config']->set('app.name', 'test-app');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->method('declareQueue');

        // deleteQueue бросает исключение — оно должно быть проглочено
        $connectMock->method('deleteQueue')
            ->willThrowException(new Exception('Queue not found'));

        $this->workerMock->method('waitResponse')->willReturn(['ok' => true]);

        // Исключение не должно пробрасываться наружу
        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);
    }

    public function testGetResponseDispatchesMessageSentAndResponseReceivedEvents(): void
    {
        Event::fake();

        $this->app['config']->set('agelxnash-queue.reply.mode', 'shared');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn(['userId' => 42]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');
        $this->workerMock->method('waitResponse')->willReturn(['ok' => true]);

        $result = $this->externalJob->getResponse($message, 'target-queue');

        $this->assertSame(['ok' => true], $result);

        Event::assertDispatched(MessageSent::class, static function (MessageSent $event): bool {
            return $event->queue === 'target-queue'
                && $event->type === 'TASK_TEST'
                && $event->params === ['userId' => 42];
        });

        Event::assertDispatched(ResponseReceived::class, static function (ResponseReceived $event): bool {
            return $event->queue === 'shared-response-queue'
                && $event->response === ['ok' => true];
        });
    }

    public function testGetResponseDispatchesResponseTimeoutEvent(): void
    {
        Event::fake();

        $this->app['config']->set('agelxnash-queue.reply.mode', 'shared');

        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([]);
        $message->method('getHandler')->willReturn('external');

        $this->workerMock->method('queueName')->willReturn('shared-response-queue');
        $this->workerMock->method('waitResponse')
            ->willThrowException(new MaxAttemptsQueueException('timeout'));

        $this->expectException(MaxAttemptsQueueException::class);

        try {
            $this->externalJob->getResponse($message, 'target-queue');
        } finally {
            Event::assertDispatched(ResponseTimeout::class, static function (ResponseTimeout $event): bool {
                return $event->queue === 'shared-response-queue';
            });
        }
    }

    public function testSendMessageEncodesDtoParamsIntoPayload(): void
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getName')->willReturn('TASK_TEST');
        $message->method('getParams')->willReturn([
            'payload' => new CheckTariffDto(userId: 123, region: 'eu'),
        ]);
        $message->method('getHandler')->willReturn('external');

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->once())
            ->method('pushRaw')
            ->willReturnCallback(function (string $payload): string {
                $data = json_decode($payload, true);

                $this->assertArrayHasKey('__dto_class', $data['data']['params']['payload']);
                $this->assertSame(CheckTariffDto::class, $data['data']['params']['payload']['__dto_class']);
                $this->assertSame(123, $data['data']['params']['payload']['__dto_data']['userId']);
                $this->assertSame('eu', $data['data']['params']['payload']['__dto_data']['region']);

                return 'job-id';
            });

        $this->externalJob->sendMessage($message, 'target-queue');
    }

    public function testSendEventWithNoSubscribersDoesNotPush(): void
    {
        $externalJob = new ExternalJob($this->connectMock, $this->workerMock, new HmacSigner(''));

        $message = $this->createMock(MessageInterface::class);
        $message->method('getHandler')->willReturn('external');
        $message->method('getName')->willReturn('EVENT_TEST');
        $message->method('getParams')->willReturn([]);

        /** @var \PHPUnit\Framework\MockObject\MockObject $connectMock */
        $connectMock = $this->connectMock;
        $connectMock->expects($this->never())->method('pushRaw');

        $externalJob->sendEvent($message);
    }
}
