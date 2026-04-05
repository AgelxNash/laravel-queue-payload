<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponseHandlerInterface;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\ResponsePrepareInterface;

class ResponseHandler implements ResponseHandlerInterface
{
    private ?string $correlationId = null;
    private mixed $response = null;
    private bool $done = false;

    /**
     * @var ResponsePrepareInterface|callable|null
     */
    private $prepare = null;

    public function __construct(
        private readonly HmacSigner $hmacSigner = new HmacSigner(),
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hasResponse(): bool
    {
        return $this->done;
    }

    /**
     * @inheritDoc
     */
    public function setCorrelationId(string|null $id = null): void
    {
        $this->correlationId = $id;
    }

    /**
     * @inheritDoc
     */
    public function getResponse(): mixed
    {
        return $this->response;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(callable $popJobCallback, string $queue): void
    {
        $this->done = false;

        /**
         * Если мы не указали ID сообщения, которое ожидаем, сразу выходим
         * Используем строгую проверку: empty("0") === true в PHP, что ломает логику
         */
        if ($this->correlationId === null) {
            return;
        }

        $job = $popJobCallback($queue);

        /**
         * В очереди может не оказаться пакетов
         */
        if (is_null($job)) {
            return;
        }

        // Верифицируем HMAC подписанного correlationId из payload
        $incomingId = $job->getJobId();
        $verifiedId = $this->hmacSigner->verify((string) $incomingId);

        if ($verifiedId === false) {
            // HMAC подпись невалидна — отбрасываем сообщение
            $job->release(5);

            return;
        }

        /**
         * Если же id сообщения в очереди не совпадает с id которое мы ищем, то откладываем пакет
         * с задержкой 5 секунд. Это предотвращает livelock при flood response-очереди чужими сообщениями.
         */
        if ($verifiedId !== $this->correlationId) {
            $job->release(5);

            return;
        }

        /**
         * Если передан обработчик пакета, то передаем ему управление с целью получить подготовленный ответ
         * Если обработчик не установлен, то получаем весь payload
         */
        $this->response = $this->getPrepare() === null ? $job->payload() : ($this->getPrepare())($job);
        $this->done = true;
        $job->delete();
    }

    /**
     * @inheritDoc
     */
    public function getPrepare(): ResponsePrepareInterface|callable|null
    {
        return $this->prepare;
    }

    /**
     * @inheritDoc
     */
    public function setPrepare(ResponsePrepareInterface|callable|null $prepare = null): void
    {
        $this->prepare = $prepare;
    }
}
