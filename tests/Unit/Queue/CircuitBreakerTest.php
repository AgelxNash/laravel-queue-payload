<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Exceptions\CircuitBreakerOpenException;
use AgelxNash\LaravelQueuePayload\Queue\CircuitBreaker;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testInitialStateIsClosed(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeout: 10);

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertSame(0, $cb->retryAfter());
    }

    public function testSuccessResetsFailures(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeout: 10);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testOpensAfterThresholdFailures(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeout: 10);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());

        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    public function testThrowsWhenOpen(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 10);

        $cb->recordFailure();
        $cb->recordFailure();

        $this->expectException(CircuitBreakerOpenException::class);
        $cb->throwIfOpen();
    }

    public function testTransitionsToHalfOpenAfterResetTimeout(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 1);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Ждём reset timeout
        sleep(2);

        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // throwIfOpen не должен выбросить в half-open (разрешаем пробную попытку)
        $cb->throwIfOpen();
        $this->assertTrue(true);
    }

    public function testRetryAfterReturnsCorrectValue(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 10);

        $cb->recordFailure();
        $cb->recordFailure();

        $retryAfter = $cb->retryAfter();
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(10, $retryAfter);
    }

    public function testResetReturnsToInitialState(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 10);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->reset();

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertSame(0, $cb->retryAfter());
    }

    public function testHalfOpenAllowsOneAttemptThenReopensOnFailure(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 1);

        $cb->recordFailure();
        $cb->recordFailure();

        sleep(2);

        // Half-open — разрешаем попытку
        $cb->throwIfOpen();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Попытка провалилась — снова open
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    public function testHalfOpenClosesOnSuccess(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, resetTimeout: 1);

        $cb->recordFailure();
        $cb->recordFailure();

        sleep(2);

        $cb->throwIfOpen();
        $cb->recordSuccess();

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }
}
