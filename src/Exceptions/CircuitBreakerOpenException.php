<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Exceptions;

use RuntimeException;

/**
 * Выбрасывается когда Circuit Breaker открыт и RPC-вызов отклонён.
 */
class CircuitBreakerOpenException extends RuntimeException
{
}
