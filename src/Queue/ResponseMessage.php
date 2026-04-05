<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;

/**
 * Класс позволяющий не указывать хандлер всякий раз, когда готовим ответ
 * Фактически, мы можем воспользоваться и @see ExternalMessage
 */
class ResponseMessage implements MessageInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly bool $success = true,
        private readonly mixed $data = null,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     * @inheritDoc
     */
    public function getParams(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return ExternalHandler::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getHandler(): string
    {
        return ExternalHandler::NAME;
    }
}
