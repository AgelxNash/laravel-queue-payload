<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

/**
 * Опциональная HMAC-подпись correlationId для защиты от подделки ответов.
 *
 * Если секрет не задан — signer работает как no-op (возвращает input без изменений).
 *
 * Формат подписанного ID: "{correlationId}.{hmac_hex}"
 *
 * Пример использования:
 * ```php
 * // Отправка: подписываем correlationId
 * $signedId = $signer->sign($correlationId);
 *
 * // Получение: верифицируем и извлекаем оригинальный ID
 * $originalId = $signer->verify($signedId); // string или false если подпись невалидна
 * ```
 */
class HmacSigner
{
    private readonly bool $enabled;

    public function __construct(
        private readonly string $secret = '',
        private readonly string $algorithm = 'sha256',
    ) {
        $this->enabled = $secret !== '';
    }

    /**
     * Подписывает correlationId. Если секрет не задан — возвращает как есть.
     */
    public function sign(string $correlationId): string
    {
        if (!$this->enabled) {
            return $correlationId;
        }

        $signature = hash_hmac($this->algorithm, $correlationId, $this->secret);

        return $correlationId . '.' . $signature;
    }

    /**
     * Верифицирует и извлекает оригинальный correlationId.
     *
     * @return string|false Оригинальный correlationId или false если подпись невалидна
     */
    public function verify(string $signedId): string|false
    {
        if (!$this->enabled) {
            return $signedId;
        }

        $parts = explode('.', $signedId, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$correlationId, $signature] = $parts;
        $expectedSignature = hash_hmac($this->algorithm, $correlationId, $this->secret);

        // timing-safe сравнение
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        return $correlationId;
    }

    /**
     * Включена ли подпись.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
