<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\ResponseMessage;
use PHPUnit\Framework\TestCase;

class ResponseMessageTest extends TestCase
{
    public function testCreatesWithDefaultValues(): void
    {
        $message = new ResponseMessage();

        $this->assertSame(ExternalHandler::NAME, $message->getName());
        $this->assertSame(ExternalHandler::NAME, $message->getHandler());
        $this->assertSame([
            'success' => true,
            'data' => null,
            'metadata' => [],
        ], $message->getParams());
    }

    public function testCreatesWithCustomValues(): void
    {
        $message = new ResponseMessage(
            success: false,
            data: ['error' => 'not found'],
            metadata: ['time' => 0.5]
        );

        $this->assertSame([
            'success' => false,
            'data' => ['error' => 'not found'],
            'metadata' => ['time' => 0.5],
        ], $message->getParams());
    }
}
