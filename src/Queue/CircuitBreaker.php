<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened;
use AgelxNash\LaravelQueuePayload\Exceptions\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Event;

/**
 * Circuit Breaker для RPC-вызовов.
 *
 * Состояния:
 * - Closed: нормальная работа, считаем failures
 * - Open: после N failures — мгновенный fail без попытки
 * - Half-Open: после resetTimeout — одна пробная попытка
 *
 * Пример:
 * ```php
 * $cb = new CircuitBreaker(failureThreshold: 5, resetTimeout: 30);
 * $cb->recordSuccess(); // сбросить счётчик
 * $cb->recordFailure(); // увеличить счётчик
 * $cb->throwIfOpen();    // выбросить если circuit open
 * ```
 */
class CircuitBreaker
{
    /**
     * Circuit states
     */
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    private int $failureCount = 0;
    private ?int $lastFailureTime = null;
    private string $state = self::STATE_CLOSED;

    /**
     * @param int $failureThreshold Количество failures до открытия circuit
     * @param int $resetTimeout Секунды до перехода в half-open
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $resetTimeout = 30,
    ) {
    }

    /**
     * Записывает успешную операцию — сбрасывает счётчик failures.
     */
    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->lastFailureTime = null;
        $this->state = self::STATE_CLOSED;
    }

    /**
     * Записывает неудачу — увеличивает счётчик и открывает circuit при превышении порога.
     */
    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();

        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;

            Event::dispatch(new CircuitBreakerOpened(
                failureCount: $this->failureCount,
                failureThreshold: $this->failureThreshold,
                retryAfterSeconds: $this->resetTimeout,
                timestamp: microtime(true),
            ));
        }
    }

    /**
     * Проверяет и выбрасывает исключение если circuit открыт.
     *
     * @throws CircuitBreakerOpenException
     */
    public function throwIfOpen(): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        if ($this->state === self::STATE_OPEN) {
            // Проверяем не пора ли перейти в half-open
            if ($this->lastFailureTime !== null && (time() - $this->lastFailureTime) >= $this->resetTimeout) {
                $this->state = self::STATE_HALF_OPEN;

                return; // Разрешаем одну пробную попытку
            }

            throw new CircuitBreakerOpenException(
                sprintf(
                    'Circuit breaker is open [failures=%d, threshold=%d, retryAfter=%ds]',
                    $this->failureCount,
                    $this->failureThreshold,
                    $this->retryAfter()
                )
            );
        }

        // Half-open: разрешаем одну попытку, но если она провалится — снова open
        // Вызывающий код должен сам вызвать recordSuccess/recordFailure
    }

    /**
     * Возвращает текущее состояние.
     */
    public function getState(): string
    {
        // Автопроверка перехода в half-open
        if ($this->state === self::STATE_OPEN
            && $this->lastFailureTime !== null
            && (time() - $this->lastFailureTime) >= $this->resetTimeout
        ) {
            $this->state = self::STATE_HALF_OPEN;
        }

        return $this->state;
    }

    /**
     * Секунд до следующей попытки (0 если closed или half-open).
     */
    public function retryAfter(): int
    {
        if ($this->lastFailureTime === null) {
            return 0;
        }

        $elapsed = time() - $this->lastFailureTime;

        return max(0, $this->resetTimeout - $elapsed);
    }

    /**
     * Сбрасывает circuit breaker в начальное состояние.
     */
    public function reset(): void
    {
        $this->failureCount = 0;
        $this->lastFailureTime = null;
        $this->state = self::STATE_CLOSED;
    }
}
