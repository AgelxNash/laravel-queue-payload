<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

/**
 * Маркерный интерфейс для DTO-объектов, которые можно передавать в params сообщений.
 *
 * DTO должны быть immutable (readonly properties) и иметь публичный конструктор
 * с именованными параметрами, совпадающими с ключами JSON.
 *
 * Пример:
 * ```php
 * class CheckTariffDto implements DtoInterface {
 *     public function __construct(
 *         public readonly int $userId,
 *         public readonly ?string $region = null,
 *     ) {}
 * }
 * ```
 */
interface DtoInterface
{
}
