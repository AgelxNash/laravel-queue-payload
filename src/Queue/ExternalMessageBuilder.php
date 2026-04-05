<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;

/**
 * Fluent Builder для создания ExternalMessage.
 *
 * Пример использования:
 * ```php
 * $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')
 *     ->params(['userId' => 12345])
 *     ->handler('go-billing-handler')
 *     ->build();
 * ```
 *
 * Builder immutable — каждый метод возвращает новый инстанс.
 */
class ExternalMessageBuilder
{
    private string $name;
    /** @var array<string, mixed> */
    /** @var array<string, mixed> */
    private array $params = [];
    private string $handler = ExternalHandler::NAME;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Создаёт новый builder с указанным именем задачи.
     */
    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Устанавливает параметры задачи.
     *
     * @param array<string, mixed> $params
     */
    public function params(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;

        return $clone;
    }

    /**
     * Добавляет один параметр к существующим.
     */
    public function param(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->params[$key] = $value;

        return $clone;
    }

    /**
     * Устанавливает системное имя обработчика (ключ 'job' в payload).
     * По умолчанию — ExternalHandler::NAME ('external').
     */
    public function handler(string $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;

        return $clone;
    }

    /**
     * Собирает и возвращает ExternalMessage.
     */
    public function build(): ExternalMessage
    {
        return new ExternalMessage(
            name: $this->name,
            params: $this->params,
            handler: $this->handler,
        );
    }

    /**
     * Алиас для build() — позволяет использовать builder как MessageInterface напрямую.
     */
    public function toMessage(): MessageInterface
    {
        return $this->build();
    }
}
