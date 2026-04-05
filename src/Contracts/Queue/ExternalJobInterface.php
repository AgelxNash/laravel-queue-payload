<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

/**
 * Реализация обмена данными между микросервисами
 */
interface ExternalJobInterface
{
    /**
     * Отправка сообщения в очередь без ожидания ответа
     *
     * @param string $queue Имя очереди, которую слушает микросервис, куда отправляется задание
     * @param string|null $correlationId ID задачи по которой отправляется ответ
     */
    public function sendMessage(MessageInterface $message, string $queue, string|null $correlationId = null): void;

    /**
     * Отправка пакета в очередь с ожиданием ответа
     *
     * @param string $queue Имя очереди, которую слушает микросервис, куда отправляется задание
     * @param ResponsePrepareInterface|callable|null $prepare Извлечение параметров из задачи Job,
     *                                                        которые будут считаться ответом
     */
    public function getResponse(
        MessageInterface $message,
        string $queue,
        ResponsePrepareInterface|callable|null $prepare = null,
    ): mixed;

    /**
     * Отправка сообщения в пулл очередей
     * На текущий момент мы должны будем через addSubscriber добавить очереди в которые нужно отправить задачу
     *
     * @TODO: в будущем видимо мы должны будем сообщение отправлять только в 1 очередь, которую слушают все
     * @see self::addSubscriber
     */
    public function sendEvent(MessageInterface $message): void;

    /**
     * Добавить слушателя событий (имя очереди)
     */
    public function addSubscriber(string $name): void;
}
