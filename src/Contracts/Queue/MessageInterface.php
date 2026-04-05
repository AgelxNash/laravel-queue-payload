<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

/**
 * Описание задачи которая будет отправлена в очередь
 */
interface MessageInterface
{
    /**
     * Название задачи
     */
    public function getName(): string;

    /**
     * Параметры задачи
     *
     * @return array<string, mixed>
     */
    public function getParams(): array;

    /**
     * Системное название хандлера (верхнеуровневый ключ 'job' в payload)
     */
    public function getHandler(): string;
}
