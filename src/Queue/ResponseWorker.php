<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseHandlerInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponsePrepareInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Enums\QueueConnections;
use AgelxNash\LaravelQueuePayload\Exceptions\CircuitBreakerOpenException;
use AgelxNash\LaravelQueuePayload\Exceptions\MaxAttemptsQueueException;
use Closure;
use Fiber;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

class ResponseWorker implements ResponseWorkerInterface
{
    /**
     * Флаг graceful shutdown
     */
    private static bool $shutdownRequested = false;

    /**
     * @param Closure(): ResponseHandlerInterface $handlerFactory Factory для создания нового handler на каждый waitResponse()
     * @param CircuitBreaker|null $circuitBreaker Circuit Breaker для RPC-вызовов (null = отключён)
     */
    public function __construct(
        private readonly Worker $worker,
        private readonly Closure $handlerFactory,
        private readonly WorkerOptions $options,
        private readonly string $queueName,
        private readonly ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->worker->setName(self::class);
    }

    /**
     * @inheritDoc
     *
     * Задаем параметры для стандартного воркера Laravel, чтобы поймать сообщение в нужном нам месте
     */
    public function waitResponse(
        string $correlationId,
        ResponsePrepareInterface|callable|null $prepare = null,
        ?string $queueName = null,
    ): mixed {
        // Создаём НОВЫЙ handler для каждого вызова — защита от race condition в persistent-средах
        $handler = ($this->handlerFactory)();
        $handler->setCorrelationId($correlationId);
        $handler->setPrepare($prepare);

        $this->registerPopHandler($handler);
        $this->registerSignalHandlers();

        // Определяем целевую очередь: переданная явно или по умолчанию
        $targetQueue = $queueName ?? $this->queueName();

        // Клонируем WorkerOptions чтобы не модифицировать shared объект
        $options = clone $this->options;
        $options->sleep = 0;

        // Если timeout не задан, ставим 60 сек по умолчанию
        $timeout = $options->timeout >= 0 ? $options->timeout : 60;
        $startTime = time();

        try {
            while (!$handler->hasResponse()) {
                if (self::$shutdownRequested) {
                    throw new MaxAttemptsQueueException(
                        sprintf('Response worker shutdown requested [correlationId=%s, queue=%s]', $correlationId, $targetQueue)
                    );
                }

                // Circuit Breaker: быстрая проверка перед каждой попыткой
                $this->circuitBreaker?->throwIfOpen();

                try {
                    $this->worker->runNextJob(
                        QueueConnections::RESPONSE->value,
                        $targetQueue,
                        $options
                    );
                } catch (CircuitBreakerOpenException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $this->circuitBreaker?->recordFailure();

                    // Если ошибка соединения с RabbitMQ — продолжаем попытки до таймаута
                    if ((time() - $startTime) >= $timeout) {
                        throw new MaxAttemptsQueueException(
                            sprintf('Response timeout exceeded [correlationId=%s, queue=%s]. Last error: %s', $correlationId, $targetQueue, $e->getMessage()),
                            0,
                            $e
                        );
                    }
                    // Короткая пауза перед повторной попыткой
                    usleep(100000); // 100ms
                }

                // runNextJob мог доставить ответ — проверяем условие цикла
                // @phpstan-ignore-next-line hasResponse может измениться после runNextJob
                if ($handler->hasResponse()) {
                    $this->circuitBreaker?->recordSuccess();
                    break;
                }

                if ((time() - $startTime) >= $timeout) {
                    $this->circuitBreaker?->recordFailure();

                    throw new MaxAttemptsQueueException(
                        sprintf('Response timeout exceeded [correlationId=%s, queue=%s]', $correlationId, $targetQueue)
                    );
                }

                // Передаем управление в Event Loop (Fiber), чтобы не блокировать процесс
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                } else {
                    // Fallback для традиционных процессов (без Fiber): ждем 50мс
                    usleep(50000);
                }
            }
        } finally {
            $this->unregisterPopHandler();
        }

        return $handler->getResponse();
    }

    /**
     * Регистрируем обработчики сигналов для graceful shutdown
     */
    protected function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        self::$shutdownRequested = false;

        pcntl_signal(SIGTERM, static function (): void {
            self::$shutdownRequested = true;
        });

        pcntl_signal(SIGINT, static function (): void {
            self::$shutdownRequested = true;
        });
    }

    protected function registerPopHandler(ResponseHandlerInterface $handler): void
    {
        /** пока в php null может быть замыканием, но это может быть в любой момент изменено */
        $this->worker::popUsing(self::class, null); // @phpstan-ignore-line final
        $this->worker::popUsing(self::class, $handler);
    }

    protected function unregisterPopHandler(): void
    {
        $this->worker::popUsing(self::class, null); // @phpstan-ignore-line final
    }

    public function queueName(): string
    {
        return $this->queueName;
    }
}
