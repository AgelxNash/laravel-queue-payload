<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Events;

use AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened;
use AgelxNash\LaravelQueuePayload\Events\MessageSent;
use AgelxNash\LaravelQueuePayload\Events\ResponseReceived;
use AgelxNash\LaravelQueuePayload\Events\ResponseTimeout;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class EventsTest extends TestCase
{
    public function testMessageSentEvent(): void
    {
        Event::fake();

        $event = new MessageSent(
            queue: 'test-queue',
            type: 'TASK_CHECK_TARIFF',
            correlationId: 'abc-123',
            params: ['userId' => 42],
            timestamp: 1234567890.0,
        );

        Event::dispatch($event);

        Event::assertDispatched(MessageSent::class, static function (MessageSent $e): bool {
            return $e->queue === 'test-queue'
                && $e->type === 'TASK_CHECK_TARIFF'
                && $e->correlationId === 'abc-123'
                && $e->params === ['userId' => 42];
        });
    }

    public function testResponseReceivedEvent(): void
    {
        Event::fake();

        $event = new ResponseReceived(
            correlationId: 'abc-123',
            queue: 'test-queue:response',
            response: ['tariff' => 'Premium'],
            waitTime: 0.5,
            timestamp: 1234567890.5,
        );

        Event::dispatch($event);

        Event::assertDispatched(ResponseReceived::class, static function (ResponseReceived $e): bool {
            return $e->correlationId === 'abc-123'
                && $e->waitTime === 0.5
                && $e->response === ['tariff' => 'Premium'];
        });
    }

    public function testResponseTimeoutEvent(): void
    {
        Event::fake();

        $event = new ResponseTimeout(
            correlationId: 'abc-123',
            queue: 'test-queue:response',
            timeoutSeconds: 60,
            timestamp: 1234567890.0,
        );

        Event::dispatch($event);

        Event::assertDispatched(ResponseTimeout::class, static function (ResponseTimeout $e): bool {
            return $e->correlationId === 'abc-123'
                && $e->timeoutSeconds === 60;
        });
    }

    public function testCircuitBreakerOpenedEvent(): void
    {
        Event::fake();

        $event = new CircuitBreakerOpened(
            failureCount: 5,
            failureThreshold: 5,
            retryAfterSeconds: 30,
            timestamp: 1234567890.0,
        );

        Event::dispatch($event);

        Event::assertDispatched(CircuitBreakerOpened::class, static function (CircuitBreakerOpened $e): bool {
            return $e->failureCount === 5
                && $e->failureThreshold === 5
                && $e->retryAfterSeconds === 30;
        });
    }
}
