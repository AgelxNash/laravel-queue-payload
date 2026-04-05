<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;
use PHPUnit\Framework\TestCase;

class ExternalMessageTest extends TestCase
{
    public function testGettersReturnCorrectValues(): void
    {
        $message = new ExternalMessage('test_name', ['param1' => 'value1']);

        $this->assertSame('test_name', $message->getName());
        $this->assertSame(['param1' => 'value1'], $message->getParams());
    }

    public function testCanBeCreatedWithoutParams(): void
    {
        $message = new ExternalMessage('another_name');

        $this->assertSame('another_name', $message->getName());
        $this->assertSame([], $message->getParams());
    }
}
