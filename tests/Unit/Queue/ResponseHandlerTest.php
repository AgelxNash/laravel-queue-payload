<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Queue\ResponseHandler;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;

class ResponseHandlerTest extends TestCase
{
    private ResponseHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ResponseHandler(new HmacSigner(''));
    }

    public function testInitialState(): void
    {
        $this->assertFalse($this->handler->hasResponse());
        $this->assertNull($this->handler->getResponse());
        $this->assertNull($this->handler->getPrepare());
    }

    public function testInvokeWithNullCorrelationId(): void
    {
        $this->handler->setCorrelationId(null);
        $called = false;
        $pop = static function () use (&$called) {
            $called = true;

            return null;
        };

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertFalse($called);
        $this->assertFalse($this->handler->hasResponse());
    }

    public function testInvokeWithEmptyStringCorrelationId(): void
    {
        // empty("") === true, но мы используем строгую проверку === null
        // Поэтому пустая строка должна пройти дальше
        $this->handler->setCorrelationId('');
        $pop = static fn () => null;

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertFalse($this->handler->hasResponse());
    }

    public function testInvokeWithZeroCorrelationId(): void
    {
        // empty("0") === true в PHP — это баг который мы исправили
        // "0" должна обрабатываться как валидный correlationId
        $this->handler->setCorrelationId('0');
        $pop = static fn () => null;

        $this->handler->__invoke($pop, 'test-queue');

        // hasResponse должен остаться false (нет job в очереди)
        $this->assertFalse($this->handler->hasResponse());
    }

    public function testInvokeWithNoJob(): void
    {
        $this->handler->setCorrelationId('123');
        $pop = static fn () => null;

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertFalse($this->handler->hasResponse());
    }

    public function testInvokeWithMismatchingCorrelationId(): void
    {
        $this->handler->setCorrelationId('123');

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getJobId')->willReturn('456');
        $jobMock->expects($this->once())->method('release')->with(5);

        $pop = static fn () => $jobMock;

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertFalse($this->handler->hasResponse());
    }

    public function testInvokeWithMatchingCorrelationId(): void
    {
        $this->handler->setCorrelationId('123');

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getJobId')->willReturn('123');
        $jobMock->method('payload')->willReturn(['foo' => 'bar']);
        $jobMock->expects($this->once())->method('delete');

        $pop = static fn () => $jobMock;

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertTrue($this->handler->hasResponse());
        $this->assertSame(['foo' => 'bar'], $this->handler->getResponse());
    }

    public function testInvokeWithPrepareCallback(): void
    {
        $this->handler->setCorrelationId('123');
        $this->handler->setPrepare(static fn ($job) => 'prepared-' . $job->getJobId());

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getJobId')->willReturn('123');
        $jobMock->expects($this->once())->method('delete');

        $pop = static fn () => $jobMock;

        $this->handler->__invoke($pop, 'test-queue');

        $this->assertTrue($this->handler->hasResponse());
        $this->assertSame('prepared-123', $this->handler->getResponse());
    }

    public function testInvokeRejectsInvalidHmacSignature(): void
    {
        $hmacSigner = new HmacSigner('my-secret');
        $handler = new ResponseHandler($hmacSigner);

        $handler->setCorrelationId('abc-123');

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getJobId')->willReturn('abc-123.wrong-signature');
        $jobMock->expects($this->once())->method('release')->with(5);

        $pop = static fn () => $jobMock;

        $handler->__invoke($pop, 'test-queue');

        $this->assertFalse($handler->hasResponse());
    }

    public function testInvokeAcceptsValidHmacSignature(): void
    {
        $hmacSigner = new HmacSigner('my-secret');
        $handler = new ResponseHandler($hmacSigner);

        $signedId = $hmacSigner->sign('abc-123');
        $handler->setCorrelationId('abc-123');

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getJobId')->willReturn($signedId);
        $jobMock->method('payload')->willReturn(['data' => 'ok']);
        $jobMock->expects($this->once())->method('delete');

        $pop = static fn () => $jobMock;

        $handler->__invoke($pop, 'test-queue');

        $this->assertTrue($handler->hasResponse());
        $this->assertSame(['data' => 'ok'], $handler->getResponse());
    }
}
