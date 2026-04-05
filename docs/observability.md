# Observability

## Обзор

Пакет генерирует Laravel-события (Events) для мониторинга RPC-вызовов, отправки сообщений и состояния Circuit Breaker.

## События

### MessageSent

Срабатывает при публикации сообщения в очередь.

**Класс:** `AgelxNash\LaravelQueuePayload\Events\MessageSent`

| Свойство | Тип | Описание |
|---|---|---|
| `$queue` | `string` | Имя очереди |
| `$type` | `string` | Тип сообщения (алиас/FQCN job) |
| `$correlationId` | `string|null` | ID корреляции |
| `$params` | `array` | Параметры сообщения |
| `$timestamp` | `float` | Время отправки (microtime) |

### ResponseReceived

Срабатывает при успешном получении ответа.

**Класс:** `AgelxNash\LaravelQueuePayload\Events\ResponseReceived`

| Свойство | Тип | Описание |
|---|---|---|
| `$correlationId` | `string` | ID корреляции |
| `$queue` | `string` | Имя response-очереди |
| `$response` | `mixed` | Данные ответа |
| `$waitTime` | `float` | Время ожидания (секунды) |
| `$timestamp` | `float` | Время получения (microtime) |

### ResponseTimeout

Срабатывает при превышении таймаута ожидания.

**Класс:** `AgelxNash\LaravelQueuePayload\Events\ResponseTimeout`

| Свойство | Тип | Описание |
|---|---|---|
| `$correlationId` | `string` | ID корреляции |
| `$queue` | `string` | Имя response-очереди |
| `$timeoutSeconds` | `int` | Настроенный таймаут |
| `$timestamp` | `float` | Время таймаута (microtime) |

### CircuitBreakerOpened

Срабатывает при открытии Circuit Breaker.

**Класс:** `AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened`

| Свойство | Тип | Описание |
|---|---|---|
| `$failureCount` | `int` | Текущее количество ошибок |
| `$failureThreshold` | `int` | Порог открытия |
| `$retryAfterSeconds` | `int` | Секунд до half-open |
| `$timestamp` | `float` | Время открытия (microtime) |

## Подписка на события

### Через Event facade

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(\AgelxNash\LaravelQueuePayload\Events\MessageSent::class, function ($event) {
    Log::info('RPC message sent', [
        'queue'         => $event->queue,
        'type'          => $event->type,
        'correlationId' => $event->correlationId,
    ]);
});

Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseReceived::class, function ($event) {
    Log::info('RPC response received', [
        'correlationId' => $event->correlationId,
        'waitTime'      => $event->waitTime,
        'queue'         => $event->queue,
    ]);
});

Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseTimeout::class, function ($event) {
    Log::warning('RPC response timeout', [
        'correlationId' => $event->correlationId,
        'timeoutSeconds' => $event->timeoutSeconds,
        'queue'         => $event->queue,
    ]);
});

Event::listen(\AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened::class, function ($event) {
    Log::error('Circuit breaker opened', [
        'failureCount'      => $event->failureCount,
        'failureThreshold'  => $event->failureThreshold,
        'retryAfterSeconds' => $event->retryAfterSeconds,
    ]);
});
```

### Через EventServiceProvider

```php
namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        \AgelxNash\LaravelQueuePayload\Events\MessageSent::class => [
            \App\Listeners\LogMessageSent::class,
        ],
        \AgelxNash\LaravelQueuePayload\Events\ResponseReceived::class => [
            \App\Listeners\LogResponseReceived::class,
        ],
        \AgelxNash\LaravelQueuePayload\Events\ResponseTimeout::class => [
            \App\Listeners\AlertResponseTimeout::class,
        ],
        \AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened::class => [
            \App\Listeners\AlertCircuitBreaker::class,
        ],
    ];
}
```

## Метрики для мониторинга

### Рекомендуемые метрики

| Метрика | Источник | Alert |
|---|---|---|
| Время ожидания ответа | `ResponseReceived::$waitTime` | > 95-го перцентиля |
| Количество таймаутов | `ResponseTimeout` | Рост за период |
| Состояние Circuit Breaker | `CircuitBreakerOpened` | Открытие circuit |
| Скорость отправки | `MessageSent` | Аномалии в throughput |

### Пример: Prometheus-метрики

```php
Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseReceived::class, function ($event) {
    // histogram_rpc_wait_time_seconds->observe($event->waitTime);
});

Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseTimeout::class, function ($event) {
    // counter_rpc_timeouts_total->inc();
});

Event::listen(\AgelxNash\LaravelQueuePayload\Events\CircuitBreakerOpened::class, function ($event) {
    // gauge_circuit_breaker_state->set(1); // 1 = open
});
```

## Логирование

### Структурированные логи

Рекомендуется использовать JSON-логи для последующего анализа:

```php
Log::channel('rpc')->info('rpc_call', [
    'event'         => 'response_received',
    'correlationId' => $event->correlationId,
    'waitTime'      => round($event->waitTime, 3),
    'queue'         => $event->queue,
    'timestamp'     => date('c', (int) $event->timestamp),
]);
```

### Канал логирования

```php
// config/logging.php
'channels' => [
    'rpc' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/rpc.log'),
        'level'  => 'info',
        'days'   => 14,
    ],
],
```
