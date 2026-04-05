<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ExternalJobInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponsePrepareInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseWorkerInterface;
use AgelxNash\LaravelQueuePayload\Events\MessageSent;
use AgelxNash\LaravelQueuePayload\Events\ResponseReceived;
use AgelxNash\LaravelQueuePayload\Events\ResponseTimeout;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class ExternalJob implements ExternalJobInterface
{
    /**
     * Название класса если его псевдоним зарегистрированный в контейнере
     * Данному классу/объекту будет передано управление для обработки задачи
     *
     * @see MessageInterface::getName()
     */
    public const JOB_CLASS = 'type';

    /**
     * Параметры класса/объекта, которые нужно передать в конструктор при инициализации
     *
     * @see self::JOB_CLASS
     * @see MessageInterface::getParams()
     */
    public const JOB_PARAMS = 'params';

    /**
     * Имя очереди, которая будет прослушиваться на наличие ответа о поставленной задаче
     */
    public const JOB_RESPONSE = 'response';

    /**
     * Переопределение response-очереди для текущего RPC-вызова
     */
    private ?string $responseQueueOverride = null;

    /**
     * @param array<int, string> $subscribers
     */
    public function __construct(
        private readonly QueueContract $connect,
        private readonly ResponseWorkerInterface $worker,
        private readonly HmacSigner $hmacSigner,
        private array $subscribers = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addSubscriber(string $name): void
    {
        $this->subscribers[] = $name;
    }

    /**
     * @inheritDoc
     */
    public function getResponse(
        MessageInterface $message,
        string $queue,
        ResponsePrepareInterface|callable|null $prepare = null,
    ): mixed {
        $correlationId = (string) Str::uuid();
        $startTime = microtime(true);

        // Подписываем correlationId если HMAC включён
        $signedId = $this->hmacSigner->sign($correlationId);

        // Определяем режим маршрутизации ответов
        $mode = (string) config('agelxnash-queue.reply.mode', 'shared');
        $responseQueue = $this->resolveResponseQueue($mode, $correlationId);

        // Устанавливаем override для createPayload()
        $this->responseQueueOverride = $responseQueue;

        try {
            $this->sendMessage($message, $queue, $signedId);

            $response = $this->worker->waitResponse(
                $correlationId,
                $prepare ?? static fn (Job $job) => $job->payload()['data'][self::JOB_PARAMS] ?? [],
                queueName: $responseQueue,
            );

            Event::dispatch(new ResponseReceived(
                correlationId: $correlationId,
                queue: $responseQueue,
                response: $response,
                waitTime: microtime(true) - $startTime,
                timestamp: microtime(true),
            ));

            return $response;
        } catch (\AgelxNash\LaravelQueuePayload\Exceptions\MaxAttemptsQueueException $e) {
            Event::dispatch(new ResponseTimeout(
                correlationId: $correlationId,
                queue: $responseQueue,
                timeoutSeconds: $this->worker instanceof ResponseWorker
                    ? 60 // default
                    : 0,
                timestamp: microtime(true),
            ));

            throw $e;
        } finally {
            $this->responseQueueOverride = null;
            $this->cleanupPerRequestQueue($mode, $responseQueue);
        }
    }

    /**
     * @inheritDoc
     */
    public function sendMessage(MessageInterface $message, string $queue, string|null $correlationId = null): void
    {
        $this->connect->pushRaw(
            $this->createPayload($message, $correlationId),
            $queue
        );

        Event::dispatch(new MessageSent(
            queue: $queue,
            type: $message->getName(),
            correlationId: $correlationId,
            params: $message->getParams(),
            timestamp: microtime(true),
        ));
    }

    /**
     * Формируем пакет данных, который корректно воспримет стандартный пакет laravel для работы с очередями
     * И на задачу с таким форматом наденет интерфейс Illuminate\Contracts\Queue\Job
     *
     * @throws JsonException
     * @see Job
     */
    protected function createPayload(MessageInterface $message, string|null $correlationId = null): string
    {
        $responseQueue = $this->responseQueueOverride
            ?? ($correlationId === null ? null : $this->worker->queueName());

        return json_encode([
            'id' => $correlationId,
            'uuid' => (string) Str::uuid(),
            'job' => $message->getHandler(),
            'data' => [
                self::JOB_RESPONSE => $responseQueue,
                self::JOB_CLASS => $message->getName(),
                self::JOB_PARAMS => DtoSerializer::encodeParams($message->getParams()),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     */
    public function sendEvent(MessageInterface $message): void
    {
        $payload = $this->createPayload($message);
        foreach ($this->subscribers as $subscriber) {
            $this->connect->pushRaw($payload, $subscriber); // @phpstan-ignore-line
        }
    }

    /**
     * Определяет response-очередь в зависимости от режима маршрутизации.
     */
    private function resolveResponseQueue(string $mode, string $correlationId): string
    {
        return match ($mode) {
            'shared' => $this->worker->queueName(),
            'per_request' => $this->createPerRequestQueue($correlationId),
            'direct_reply_to' => $this->createPerRequestQueue($correlationId), // fallback на per_request
            default => $this->worker->queueName(), // fallback на shared для неизвестного режима
        };
    }

    /**
     * Создаёт временную per-request очередь с TTL.
     *
     * @throws RuntimeException
     */
    private function createPerRequestQueue(string $correlationId): string
    {
        $queueName = sprintf(
            '%s.rpc.reply.%s',
            Str::slug((string) config('app.name', 'app')),
            $correlationId
        );

        $ttl = (int) config('agelxnash-queue.reply.per_request_ttl', 60);
        $ttlMs = $ttl * 1000;

        if (! $this->connect instanceof RabbitMQQueue) {
            throw new RuntimeException(
                sprintf(
                    'per_request reply mode requires RabbitMQQueue driver, got %s',
                    $this->connect::class
                )
            );
        }

        $this->connect->declareQueue(
            name: $queueName,
            durable: false,
            autoDelete: true,
            arguments: [
                'x-expires' => $ttlMs,
                'x-message-ttl' => $ttlMs,
            ]
        );

        return $queueName;
    }

    /**
     * Удаляет временную per-request очередь (best-effort).
     */
    private function cleanupPerRequestQueue(string $mode, string $queueName): void
    {
        if (! in_array($mode, ['per_request', 'direct_reply_to'], true)) {
            return;
        }

        try {
            if ($this->connect instanceof RabbitMQQueue) {
                $this->connect->deleteQueue($queueName);
            }
        } catch (Throwable) {
            // Best-effort cleanup — игнорируем ошибки удаления
        }
    }
}
