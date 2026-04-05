<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

interface ResponseWorkerInterface
{
    /**
     * Ожидание ответа в очереди
     *
     * @param string $correlationId Идентификатор задачи, которую ожидаем получить
     * @param ResponsePrepareInterface|callable|null $prepare Обработчик пакета данных
     * @param string|null $queueName Имя очереди для прослушивания (null = очередь по умолчанию)
     */
    public function waitResponse(
        string $correlationId,
        ResponsePrepareInterface|callable|null $prepare = null,
        ?string $queueName = null,
    ): mixed;

    public function queueName(): string;
}
