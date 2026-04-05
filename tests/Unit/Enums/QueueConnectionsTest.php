<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Enums;

use AgelxNash\LaravelQueuePayload\Enums\QueueConnections;
use PHPUnit\Framework\TestCase;

class QueueConnectionsTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('sync', QueueConnections::SYNC->value);
        $this->assertSame('request', QueueConnections::REQUEST->value);
        $this->assertSame('response', QueueConnections::RESPONSE->value);
    }
}
