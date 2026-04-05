<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;

/**
 * Базовая реализация сообщения в очередь
 * При желании, мы можем реализовать свой класс описывающий интерфейс, тогда параметры сообщений сможем валидировать
 * на этапе сборки объекта
 */
class ExternalMessage implements MessageInterface
{
    /**
     * @param string $name Название задачи
     * @param array<string, mixed> $params Параметры задачи
     * @param string $handler Системное название хандлера
     */
    public function __construct(
        private readonly string $name,
        private readonly array $params = [],
        private readonly string $handler = ExternalHandler::NAME,
    ) {
    }

    /**
     * Создаёт Fluent Builder для данного сообщения.
     *
     * Пример:
     * ```php
     * ExternalMessage::make('TASK_CHECK_TARIFF')
     *     ->params(['userId' => 12345])
     *     ->build();
     * ```
     */
    public static function make(string $name): ExternalMessageBuilder
    {
        return ExternalMessageBuilder::make($name);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     * @inheritDoc
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @inheritDoc
     */
    public function getHandler(): string
    {
        return $this->handler;
    }
}
