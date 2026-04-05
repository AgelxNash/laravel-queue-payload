# Конфигурация пакета

## Публикация конфига

```bash
php artisan vendor:publish --provider="AgelxNash\LaravelQueuePayload\ServiceProvider"
```

Файл: `config/agelxnash-queue.php`

## Полная структура конфига

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Настройки очереди
    |--------------------------------------------------------------------------
    */
    'queue' => [
        // Таймаут ожидания ответа из очереди (в секундах)
        // Используется WorkerOptions::timeout. -1 = бесконечное ожидание (не рекомендуется)
        'timeout' => env('QUEUE_RESPONSE_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | HMAC-подпись correlationId
    |--------------------------------------------------------------------------
    */
    'hmac' => [
        'secret'    => env('QUEUE_HMAC_SECRET', ''),
        'algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker для RPC-вызовов
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'enabled'           => (bool) env('QUEUE_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('QUEUE_CIRCUIT_BREAKER_FAILURES', 5),
        'reset_timeout'     => (int) env('QUEUE_CIRCUIT_BREAKER_RESET', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowlist job-классов
    |--------------------------------------------------------------------------
    */
    'allowed_jobs' => [],

    /*
    |--------------------------------------------------------------------------
    | Режим маршрутизации RPC-ответов
    |--------------------------------------------------------------------------
    */
    'reply' => [
        'mode'            => env('QUEUE_RPC_REPLY_MODE', 'shared'),
        'per_request_ttl' => (int) env('QUEUE_RPC_PER_REQUEST_TTL', 60),
    ],
];
```

## ENV-переменные

| Переменная | По умолчанию | Описание |
|---|---|---|
| `QUEUE_RESPONSE_TIMEOUT` | `60` | Таймаут ожидания RPC-ответа (секунды). `-1` = бесконечное ожидание |
| `QUEUE_RPC_REPLY_MODE` | `shared` | Режим маршрутизации: `shared`, `per_request`, `direct_reply_to` |
| `QUEUE_RPC_PER_REQUEST_TTL` | `60` | TTL временных per-request очередей (секунды) |
| `QUEUE_HMAC_SECRET` | `''` (пусто) | Секрет для HMAC-подписи correlationId. Пустой = подпись отключена |
| `QUEUE_CIRCUIT_BREAKER_ENABLED` | `true` | Включить Circuit Breaker для RPC |
| `QUEUE_CIRCUIT_BREAKER_FAILURES` | `5` | Порог ошибок до открытия circuit |
| `QUEUE_CIRCUIT_BREAKER_RESET` | `30` | Секунд до перехода в half-open |

## Режимы маршрутизации ответов (`reply.mode`)

### `shared` (по умолчанию)

- Использует общую response-очередь, заданную в `config/queue.php` → `connections.response.queue`
- Все RPC-ответы приходят в одну очередь
- `ResponseHandler` фильтрует сообщения по `correlationId`
- Совместим с любым драйвером очереди

### `per_request`

- Создаёт отдельную временную очередь для каждого RPC-запроса
- Имя очереди: `{app-slug}.rpc.reply.{correlationId}`
- Параметры очереди:
  - `durable: false`
  - `auto_delete: true`
  - `x-expires: {TTL}ms`
  - `x-message-ttl: {TTL}ms`
- Автоматическая очистка очереди после получения ответа (best-effort)
- **Требование:** драйвер `RabbitMQQueue` (пакет `vladimir-yuldashev/laravel-queue-rabbitmq`)
- При использовании другого драйвера будет выброшено `RuntimeException`

### `direct_reply_to` (experimental)

- В текущей версии — fallback на `per_request`
- Не рекомендуется для production

## Настройка RabbitMQ соединений

В `config/queue.php` необходимы два соединения:

```php
use AgelxNash\LaravelQueuePayload\Enums\QueueConnections;

'connections' => [
    QueueConnections::REQUEST->value => [
        'driver' => 'rabbitmq',
        'hosts' => [[
            'host'     => env('RABBITMQ_HOST', 'rabbit'),
            'port'     => env('RABBITMQ_PORT', 5672),
            'user'     => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'    => env('RABBITMQ_VHOST', '/'),
        ]],
        'queue' => 'my-service:' . QueueConnections::REQUEST->value,
    ],
    QueueConnections::RESPONSE->value => [
        'driver' => 'rabbitmq',
        'hosts' => [[
            'host'     => env('RABBITMQ_HOST', 'rabbit'),
            'port'     => env('RABBITMQ_PORT', 5672),
            'user'     => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'    => env('RABBITMQ_VHOST', '/'),
        ]],
        'queue' => 'my-service:' . QueueConnections::RESPONSE->value,
    ],
],
```

> **Примечание:** В режиме `per_request` соединение `response` может быть пустым — временные очереди создаются динамически.

## Allowlist job-классов

```php
'allowed_jobs' => [
    // Маппинг алиаса → FQCN
    'TASK_CHECK_TARIFF' => \App\Jobs\CheckUserTariffJob::class,

    // Разрешить алиас как есть (должен быть забинжен в контейнере)
    'TRIGGER_EVENT'     => null,
],
```

Поведение:

- **Пустой массив** (по умолчанию): разрешены все алиасы/FQCN
- **Непустой массив**: разрешены **только** ключи из массива
- **Значение `null`**: разрешить алиас как есть (container resolve)
- **Значение FQCN string**: использовать указанный класс вместо алиаса

## Circuit Breaker

Состояния:

1. **Closed** — нормальная работа, счётчик failures = 0
2. **Open** — после `failure_threshold` последовательных ошибок. RPC-вызовы мгновенно падают с `CircuitBreakerOpenException`
3. **Half-Open** — через `reset_timeout` секунд. Разрешается одна пробная попытка

Переходы:

- `closed → open`: при достижении `failure_threshold` ошибок
- `open → half-open`: через `reset_timeout` секунд
- `half-open → closed`: при успешной пробной попытке
- `half-open → open`: при ошибке пробной попытки

## HMAC-подпись

Формат подписанного ID: `{correlationId}.{hmac_hex}`

- При пустом `secret` — signer работает как no-op (возвращает input без изменений)
- Алгоритм: настраивается через `hmac.algorithm` (по умолчанию `sha256`)
- Используется timing-safe сравнение (`hash_equals`)
- Один и тот же секрет должен быть на **всех** RPC-сервисах
