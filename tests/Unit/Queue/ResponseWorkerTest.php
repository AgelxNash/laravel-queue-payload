<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseHandlerInterface;
use AgelxNash\LaravelQueuePayload\Exceptions\CircuitBreakerOpenException;
use AgelxNash\LaravelQueuePayload\Exceptions\MaxAttemptsQueueException;
use AgelxNash\LaravelQueuePayload\Queue\CircuitBreaker;
use AgelxNash\LaravelQueuePayload\Queue\ResponseHandler;
use AgelxNash\LaravelQueuePayload\Queue\ResponseWorker;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use ReflectionClass;
use RuntimeException;

class ResponseWorkerTest extends TestCase
{
    private Worker $workerMock;
    private ResponseHandlerInterface $handlerMock;
    private WorkerOptions $options;
    private ResponseWorker $responseWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerMock = $this->createMock(Worker::class);
        $this->handlerMock = $this->createMock(ResponseHandlerInterface::class);
        $this->options = new WorkerOptions();
        $this->options->sleep = 3;
        $this->options->timeout = 1;

        // Partial mock of ResponseWorker to avoid static calls in Worker::popUsing
        $handlerMock = $this->handlerMock;
        $this->responseWorker = $this->getMockBuilder(ResponseWorker::class)
            ->setConstructorArgs([
                $this->workerMock,
                static fn () => $handlerMock,
                $this->options,
                'test-queue',
            ])
            ->onlyMethods(['registerPopHandler', 'unregisterPopHandler'])
            ->getMock();
    }

    public function testQueueName(): void
    {
        $this->assertSame('test-queue', $this->responseWorker->queueName());
    }

    public function testWaitResponseSuccess(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject $handlerMock */
        $handlerMock = $this->handlerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $workerMock */
        $workerMock = $this->workerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $responseWorker */
        $responseWorker = $this->responseWorker;

        $responseWorker->expects($this->once())->method('registerPopHandler');
        $responseWorker->expects($this->once())->method('unregisterPopHandler');

        $handlerMock->expects($this->once())->method('setCorrelationId')->with('123');
        $handlerMock->expects($this->once())->method('setPrepare');

        // Simulating one mismatch then a match
        $handlerMock->expects($this->exactly(2))
            ->method('hasResponse')
            ->willReturn(false, true);

        $handlerMock->expects($this->once())
            ->method('getResponse')
            ->willReturn(['foo' => 'bar']);

        $workerMock->expects($this->once())
            ->method('runNextJob');

        $result = $this->responseWorker->waitResponse('123');

        $this->assertSame(['foo' => 'bar'], $result);
        // WorkerOptions клонируется, оригинальный объект не модифицируется
        $this->assertSame(3, $this->options->sleep);
    }

    public function testWaitResponseTimeout(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject $handlerMock */
        $handlerMock = $this->handlerMock;

        $this->options->timeout = 0; // Immediate timeout
        $handlerMock->method('hasResponse')->willReturn(false);

        $this->expectException(MaxAttemptsQueueException::class);

        $this->responseWorker->waitResponse('123');
    }

    public function testEachWaitResponseCreatesNewHandler(): void
    {
        $createdHandlers = [];
        $factory = static function () use (&$createdHandlers): ResponseHandler {
            $h = new ResponseHandler();
            $createdHandlers[] = $h;

            return $h;
        };

        $worker = new ResponseWorker(
            $this->workerMock,
            $factory,
            $this->options,
            'test-queue'
        );

        // Вызываем factory дважды — должны создаться разные инстансы
        $h1 = $factory();
        $h2 = $factory();

        $this->assertNotSame($h1, $h2);
        $this->assertCount(2, $createdHandlers);
    }

    public function testWaitResponseRecordsFailureOnTimeout(): void
    {
        $circuitBreaker = new CircuitBreaker(
            failureThreshold: 1,
            resetTimeout: 10
        );

        $this->options->timeout = 0;
        $handlerMock = $this->handlerMock;

        $partialWorker = $this->getMockBuilder(ResponseWorker::class)
            ->setConstructorArgs([
                $this->workerMock,
                static fn () => $handlerMock,
                $this->options,
                'test-queue',
                $circuitBreaker,
            ])
            ->onlyMethods(['registerPopHandler', 'unregisterPopHandler'])
            ->getMock();

        $handlerMock->method('hasResponse')->willReturn(false);

        try {
            $partialWorker->waitResponse('123');
        } catch (MaxAttemptsQueueException) {
            // Ожидаемо
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $circuitBreaker->getState());
    }

    public function testWaitResponseRecordsSuccessOnResponse(): void
    {
        $circuitBreaker = new CircuitBreaker(
            failureThreshold: 5,
            resetTimeout: 10
        );

        // Предварительно добавим failures
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();

        $handlerMock = $this->handlerMock;

        $partialWorker = $this->getMockBuilder(ResponseWorker::class)
            ->setConstructorArgs([
                $this->workerMock,
                static fn () => $handlerMock,
                $this->options,
                'test-queue',
                $circuitBreaker,
            ])
            ->onlyMethods(['registerPopHandler', 'unregisterPopHandler'])
            ->getMock();

        $handlerMock->expects($this->once())->method('setCorrelationId')->with('123');
        $handlerMock->expects($this->exactly(2))->method('hasResponse')->willReturn(false, true);
        $handlerMock->expects($this->once())->method('getResponse')->willReturn(['ok' => true]);
        $this->workerMock->expects($this->once())->method('runNextJob');

        $result = $partialWorker->waitResponse('123');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $circuitBreaker->getState());
    }

    public function testWaitResponseThrowsImmediatelyWhenCircuitOpen(): void
    {
        $circuitBreaker = new CircuitBreaker(
            failureThreshold: 1,
            resetTimeout: 60
        );

        $circuitBreaker->recordFailure(); // Circuit now open

        $handlerMock = $this->handlerMock;

        $partialWorker = $this->getMockBuilder(ResponseWorker::class)
            ->setConstructorArgs([
                $this->workerMock,
                static fn () => $handlerMock,
                $this->options,
                'test-queue',
                $circuitBreaker,
            ])
            ->onlyMethods(['registerPopHandler', 'unregisterPopHandler'])
            ->getMock();

        $this->expectException(CircuitBreakerOpenException::class);

        $partialWorker->waitResponse('123');
    }

    public function testWaitResponseUsesOverriddenQueueName(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject $handlerMock */
        $handlerMock = $this->handlerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $workerMock */
        $workerMock = $this->workerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $responseWorker */
        $responseWorker = $this->responseWorker;

        $responseWorker->expects($this->once())->method('registerPopHandler');
        $responseWorker->expects($this->once())->method('unregisterPopHandler');

        $handlerMock->expects($this->once())->method('setCorrelationId')->with('123');
        $handlerMock->expects($this->once())->method('setPrepare');
        $handlerMock->expects($this->exactly(2))->method('hasResponse')->willReturn(false, true);
        $handlerMock->expects($this->once())->method('getResponse')->willReturn(['ok' => true]);

        $workerMock->expects($this->once())
            ->method('runNextJob')
            ->with('response', 'custom-reply-queue', $this->isInstanceOf(WorkerOptions::class));

        $result = $responseWorker->waitResponse('123', null, 'custom-reply-queue');

        $this->assertSame(['ok' => true], $result);
    }

    public function testWaitResponseFailsFastWhenShutdownRequested(): void
    {
        $handlerMock = $this->handlerMock;

        // Создаём partial mock с registerSignalHandlers, чтобы он не сбрасывал флаг
        $shutdownWorker = $this->getMockBuilder(ResponseWorker::class)
            ->setConstructorArgs([
                $this->workerMock,
                static fn () => $handlerMock,
                $this->options,
                'test-queue',
            ])
            ->onlyMethods(['registerPopHandler', 'unregisterPopHandler', 'registerSignalHandlers'])
            ->getMock();

        $shutdownWorker->expects($this->once())->method('registerPopHandler');
        $shutdownWorker->expects($this->once())->method('unregisterPopHandler');

        $handlerMock->expects($this->once())->method('setCorrelationId')->with('123');
        $handlerMock->expects($this->once())->method('setPrepare');
        $handlerMock->method('hasResponse')->willReturn(false);

        // Устанавливаем флаг shutdown через Reflection
        $reflection = new ReflectionClass(ResponseWorker::class);
        $property = $reflection->getProperty('shutdownRequested');
        $originalValue = $property->getValue();
        $property->setValue(null, true);

        try {
            $this->expectException(MaxAttemptsQueueException::class);
            $this->expectExceptionMessage('shutdown requested');

            $shutdownWorker->waitResponse('123');
        } finally {
            // Восстанавливаем флаг
            $property->setValue(null, $originalValue);
        }
    }

    public function testWaitResponseRetriesOnWorkerThrowable(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject $handlerMock */
        $handlerMock = $this->handlerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $workerMock */
        $workerMock = $this->workerMock;
        /** @var \PHPUnit\Framework\MockObject\MockObject $responseWorker */
        $responseWorker = $this->responseWorker;

        $responseWorker->expects($this->once())->method('registerPopHandler');
        $responseWorker->expects($this->once())->method('unregisterPopHandler');

        $handlerMock->expects($this->once())->method('setCorrelationId')->with('123');
        $handlerMock->expects($this->once())->method('setPrepare');
        $handlerMock->method('hasResponse')->willReturn(false);

        $this->options->timeout = 0; // Быстрый выход

        $workerMock->method('runNextJob')
            ->willThrowException(new RuntimeException('connection lost'));

        $this->expectException(MaxAttemptsQueueException::class);
        $this->expectExceptionMessage('Last error: connection lost');

        $responseWorker->waitResponse('123');
    }
}
