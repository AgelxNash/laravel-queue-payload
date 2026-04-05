<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Exceptions;

use AgelxNash\LaravelQueuePayload\Exceptions\MaxAttemptsQueueException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MaxAttemptsQueueExceptionTest extends TestCase
{
    public function testExceptionInheritance(): void
    {
        $exception = new MaxAttemptsQueueException('Test error message');

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Test error message', $exception->getMessage());
    }
}
