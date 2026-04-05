<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

/**
 * Фильтр для пакетов из response очереди
 */
interface ResponseHandlerInterface
{
    /**
     * Связывание ответа с конкретной задачей на которую возвращается ответ
     */
    public function setCorrelationId(string|null $id = null): void;

    /**
     * Поскольку ошибки при ожидании ответа из очереди перехватываются уровнем выше, мы будем оперировать флагом
     */
    public function hasResponse(): bool;

    /**
     * Получение ответа из очереди
     */
    public function getResponse(): mixed;

    /**
     * Установить обработчик Illuminate\Contracts\Queue\Job пакета, который будет преобразовывать данные
     * в нужный нам формат. Если установить null, то получим весь payload
     */
    public function setPrepare(ResponsePrepareInterface|callable|null $prepare = null): void;

    /**
     * Получить зарегистрированный обработчик для пакета Illuminate\Contracts\Queue\Job
     */
    public function getPrepare(): ResponsePrepareInterface|callable|null;

    /**
     * Основная логика фильтрации
     *
     * @param callable $popJobCallback Замыкание из конкретной реализации очереди для получения сообщения от очереди
     * @param string $queue Имя очереди
     */
    public function __invoke(callable $popJobCallback, string $queue): void;
}
