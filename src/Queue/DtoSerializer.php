<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\DtoInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Сериализация и десериализация DTO-объектов для передачи через очередь.
 */
class DtoSerializer
{
    /**
     * Сериализует DTO в array для JSON.
     *
     * @return array<string, mixed>
     */
    public static function serialize(DtoInterface $dto): array
    {
        $reflection = new ReflectionClass($dto);
        $data = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $data[$property->getName()] = $property->getValue($dto);
        }

        return $data;
    }

    /**
     * Десериализует array в DTO-объект.
     *
     * @template T of DtoInterface
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     */
    public static function deserialize(string $class, array $data): DtoInterface
    {
        if (!class_exists($class)) {
            throw new RuntimeException("DTO class '{$class}' not found");
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException("DTO class '{$class}' must have a constructor");
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                $type = $param->getType();

                // Рекурсивная десериализация вложенных DTO
                if ($type instanceof ReflectionNamedType) {
                    $typeName = $type->getName();
                    if (is_subclass_of($typeName, DtoInterface::class) && is_array($value)) {
                        $value = self::deserialize($typeName, $value);
                    }
                }

                $args[$name] = $value;
            } elseif (!$param->isDefaultValueAvailable()) {
                throw new RuntimeException(
                    "Missing required DTO parameter '{$name}' for class '{$class}'"
                );
            }
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Проверяет является ли значение DTO.
     */
    public static function isDto(mixed $value): bool
    {
        return $value instanceof DtoInterface;
    }

    /**
     * Кодирует params: DTO → array, array → как есть.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function encodeParams(array $params): array
    {
        $encoded = [];
        foreach ($params as $key => $value) {
            if (self::isDto($value)) {
                $encoded[$key] = [
                    '__dto_class' => $value::class,
                    '__dto_data' => self::serialize($value),
                ];
            } else {
                $encoded[$key] = $value;
            }
        }

        return $encoded;
    }

    /**
     * Декодирует params: array с DTO-маркерами → array с DTO-объектами.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function decodeParams(array $params): array
    {
        $decoded = [];
        foreach ($params as $key => $value) {
            if (is_array($value) && isset($value['__dto_class'], $value['__dto_data'])) {
                /** @phpstan-ignore argument.templateType */
                $decoded[$key] = self::deserialize($value['__dto_class'], $value['__dto_data']);
            } else {
                $decoded[$key] = $value;
            }
        }

        return $decoded;
    }
}
