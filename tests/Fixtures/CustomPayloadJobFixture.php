<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Fixtures;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;

/**
 * Демонстрирует переопределение createPayload() для полностью кастомной структуры JSON.
 * Соответствует паттерну из Advanced раздела README.
 */
class CustomPayloadJobFixture extends ExternalJob
{
    protected function createPayload(MessageInterface $message, string|null $correlationId = null): string
    {
        return json_encode([
            'id' => $correlationId,
            'task' => $message->getHandler(),  // Кастомный ключ вместо 'job'
            'payload' => $message->getParams(),
        ], JSON_THROW_ON_ERROR);
    }
}
