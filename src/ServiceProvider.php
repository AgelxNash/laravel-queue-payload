<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Enums\QueueConnections;
use AgelxNash\LaravelQueuePayload\Queue\CircuitBreaker;
use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use AgelxNash\LaravelQueuePayload\Queue\ResponseHandler;
use AgelxNash\LaravelQueuePayload\Queue\ResponseWorker;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

/**
 * Base class for all center based Service Providers
 */
class ServiceProvider extends IlluminateServiceProvider
{
    public const PACKAGE_NAME = 'laravel-queue-payload';

    /**
     * Register any application services
     */
    public function register(): void
    {
        $this->setConfig();

        $this->app->bind(ExternalHandler::NAME, ExternalHandler::class);
        $this->app->bind(
            QueueContract::class,
            static fn (\Illuminate\Contracts\Foundation\Application $app) => $app->make(Factory::class)->connection(
                QueueConnections::REQUEST->value
            )
        );

        // HMAC Signer — singleton, один на все запросы
        $this->app->singleton(HmacSigner::class, static function (): HmacSigner {
            $hmacConfig = config('agelxnash-queue.hmac', []);

            return new HmacSigner(
                secret: $hmacConfig['secret'] ?? '',
                algorithm: $hmacConfig['algorithm'] ?? 'sha256',
            );
        });

        $this->app->bind(
            ResponseWorkerInterface::class,
            static function (\Illuminate\Contracts\Foundation\Application $app) {
                $cbConfig = config('agelxnash-queue.circuit_breaker', []);
                $circuitBreaker = ($cbConfig['enabled'] ?? true)
                    ? new CircuitBreaker(
                        failureThreshold: $cbConfig['failure_threshold'] ?? 5,
                        resetTimeout: $cbConfig['reset_timeout'] ?? 30,
                    )
                    : null;

                $hmacSigner = $app->make(HmacSigner::class);

                return new ResponseWorker(
                    worker: $app->make(Worker::class, [
                        'isDownForMaintenance' => static fn () => false,
                    ]),
                    handlerFactory: static fn () => new ResponseHandler($hmacSigner),
                    options: new WorkerOptions(),
                    queueName: config(sprintf('queue.connections.%s.queue', QueueConnections::RESPONSE->value)),
                    circuitBreaker: $circuitBreaker,
                );
            }
        );

        $this->app->singleton(Contracts\Queue\ExternalJobInterface::class, static function (\Illuminate\Contracts\Foundation\Application $app) {
            return new Queue\ExternalJob(
                connect: $app->make(QueueContract::class),
                worker: $app->make(ResponseWorkerInterface::class),
                hmacSigner: $app->make(HmacSigner::class),
            );
        });
    }

    protected function setConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/agelxnash-queue.php',
            'agelxnash-queue'
        );
    }

    /**
     * Bootstrap any application services
     */
    public function boot(): void
    {
        $this->publishesConfig();
    }

    protected function publishesConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/agelxnash-queue.php' => config_path('agelxnash-queue.php'),
        ], static::PACKAGE_NAME);
    }
}
