<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Queue\ResponseHandler;
use AgelxNash\LaravelQueuePayload\Queue\ResponseWorker;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Contracts\Queue\Queue as QueueContract;

class ServiceProviderTest extends TestCase
{
    public function testBindingsAreRegistered(): void
    {
        $this->assertInstanceOf(
            ExternalHandler::class,
            $this->app->make(ExternalHandler::NAME)
        );

        $this->assertTrue($this->app->bound(QueueContract::class));
        $this->assertTrue($this->app->bound(ResponseWorkerInterface::class));
        $this->assertTrue($this->app->bound(\AgelxNash\LaravelQueuePayload\Contracts\Queue\ExternalJobInterface::class));
    }

    public function testConfigIsMerged(): void
    {
        $config = config('agelxnash-queue.queue');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('timeout', $config);
        $this->assertArrayHasKey('allowed_jobs', config('agelxnash-queue'));
    }

    public function testResponseHandlerHasNoConstructorArgs(): void
    {
        $handler = $this->app->make(ResponseHandler::class);
        $this->assertInstanceOf(ResponseHandler::class, $handler);
    }

    public function testHmacSignerIsSingletonAndReadsConfig(): void
    {
        $this->app['config']->set('agelxnash-queue.hmac.secret', 'test-secret');
        $this->app['config']->set('agelxnash-queue.hmac.algorithm', 'sha256');

        $a = $this->app->make(HmacSigner::class);
        $b = $this->app->make(HmacSigner::class);

        $this->assertSame($a, $b);
        $this->assertTrue($a->isEnabled());
    }

    public function testResponseWorkerCanBeResolvedWhenCircuitBreakerDisabled(): void
    {
        $this->app['config']->set('agelxnash-queue.circuit_breaker.enabled', false);
        $this->app['config']->set('queue.connections.response.queue', 'test-response-queue');

        $worker = $this->app->make(ResponseWorkerInterface::class);

        $this->assertInstanceOf(ResponseWorker::class, $worker);
    }
}
