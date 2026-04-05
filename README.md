# Laravel Queue Payload

Пакет для Laravel, обеспечивающий удобное и прозрачное межсервисное взаимодействие (RPC / Event Messaging) через RabbitMQ.

Стандартные очереди Laravel жёстко привязаны к внутренним классам фреймворка (сериализация объектов через `CallQueuedHandler`). Это делает невозможным чтение и отправку таких очередей из других языков — Go, Node.js, Python.

**Этот пакет решает проблему обмена данными**, преобразовывая сложный нативный payload Laravel в простой кроссплатформенный JSON, а также добавляет поддержку **Request-Response (RPC) поверх очередей**.

[Статья на Хабре: Очереди в Laravel: заглядываем под капот и строим микросервисы](https://habr.com/ru/articles/748288/)

---

## Содержание

- [В чём разница форматов](#в-чём-разница-форматов)
- [Требования](#требования)
- [Установка](#установка)
- [Архитектура: воркеры и роли](#архитектура-воркеры-и-роли)
- [Конфигурация RabbitMQ](#конфигурация-rabbitmq)
- [Конфигурация пакета](#конфигурация-пакета)
  - [Режимы маршрутизации ответов](#режимы-маршрутизации-ответов)
  - [Allowlist job-классов](#allowlist-job-классов)
  - [HMAC-подпись](#hmac-подпись)
  - [Circuit Breaker](#circuit-breaker)
- [Использование](#использование)
  - [RPC — ожидание ответа](#rpc--ожидание-ответа)
  - [Fire-and-Forget — без ожидания](#fire-and-forget--без-ожидания)
  - [Fluent Builder](#fluent-builder)
  - [Получение задач и отправка ответа](#получение-задач-и-отправка-ответа)
  - [Event Broadcasting](#event-broadcasting)
  - [DTO для параметров](#dto-для-параметров)
  - [Кастомная сериализация при dispatch()](#кастомная-сериализация-при-dispatch)
- [Observability](#observability)
- [Безопасность](#безопасность)
- [Миграция shared → per_request](#миграция-shared--per_request)
- [Troubleshooting](#troubleshooting)
- [Документация / Wiki](#документация--wiki)
- [Лицензия](#лицензия)

---

## В чём разница форматов

Главная задача пакета — перехватывать входящие/исходящие задачи и формировать понятный JSON.

❌ **Стандартный Payload Laravel (Native):**

```json
{
  "uuid": "59f3007b-e63c-4c71-b298-885563664cd6",
  "displayName": "App\\Jobs\\CheckUserTariffJob",
  "job": "Illuminate\\Queue\\CallQueuedHandler@call",
  "data": {
    "commandName": "App\\Jobs\\CheckUserTariffJob",
    "command": "O:28:\"App\\Jobs\\CheckUserTariffJob\":1:{s:6:\"userId\";i:12345;}"
  }
}
```

*Минусы: жёсткая привязка к PHP-сериализации. Микросервис на Go/Python это распарсить не сможет.*

✅ **Кроссплатформенный Payload (этот пакет):**

```json
{
  "uuid": "59f3007b-e63c-4c71-b298-885563664cd3",
  "id": "123e4567-e89b-12d3-a456-426614174000",
  "job": "external",
  "data": {
    "type": "TASK_CHECK_TARIFF",
    "response": "auth-clients:response",
    "params": {
      "userId": 12345
    }
  }
}
```

*Плюсы: никаких сериализованных объектов. Любой внешний сервис может прислать такой JSON. Сигналом для Laravel является ключ `"job": "external"`.*

---

## Требования

| Зависимость | Версия |
|---|---|
| PHP | ^8.2 |
| Laravel (illuminate/queue) | ^10.0 \| ^11.0 \| ^12.0 |
| vladimir-yuldashev/laravel-queue-rabbitmq | ^13.3 \| ^14.0 |

> **Важно:** режимы `per_request` и `direct_reply_to` требуют драйвер `RabbitMQQueue` (пакет `vladimir-yuldashev/laravel-queue-rabbitmq`). Режим `shared` работает с любым драйвером.

---

## Установка

```bash
composer require agelxnash/laravel-queue-payload
```

Опубликуйте конфиг:

```bash
php artisan vendor:publish --provider="AgelxNash\LaravelQueuePayload\ServiceProvider"
```

---

## Архитектура: воркеры и роли

### Сервис-Получатель (выполняет работу)

Постоянно слушает очередь штатным демоном Laravel:

```bash
php artisan queue:work request
```

Получает упрощённый JSON, находит локальную Job по алиасу из `type`, мапит параметры и выполняет бизнес-логику.

### Сервис-Отправитель (запрашивает работу)

Дополнительные фоновые воркеры **не нужны**. Класс `ExternalJob` — встроенный хелпер пакета. При RPC-вызове `ExternalJob` автоматически ожидает ответ, используя `ResponseWorker` с поддержкой Fiber (не блокируя PHP-процесс целиком).

---

## Конфигурация RabbitMQ

В каждом микросервисе в `config/queue.php` должны быть **2 соединения**:

```php
use AgelxNash\LaravelQueuePayload\Enums\QueueConnections;

[
    QueueConnections::REQUEST->value => [
        'driver' => 'rabbitmq',
        'hosts' => [[
            'host'     => env('RABBITMQ_HOST', 'rabbit'),
            'port'     => env('RABBITMQ_PORT', 5672),
            'user'     => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'    => env('RABBITMQ_VHOST', '/'),
        ]],
        'queue' => 'billing-service:' . QueueConnections::REQUEST->value,
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
        'queue' => 'billing-service:' . QueueConnections::RESPONSE->value,
    ],
]
```

Очередь должна иметь префикс сервиса: `auth-clients:request`, `billing-service:request` и т.д.

---

## Конфигурация пакета

Файл `config/agelxnash-queue.php`:

```php
return [
    'queue' => [
        // Таймаут ожидания ответа (секунды). -1 = бесконечное ожидание
        'timeout' => env('QUEUE_RESPONSE_TIMEOUT', 60),
    ],

    // Режим маршрутизации RPC-ответов
    'reply' => [
        'mode'            => env('QUEUE_RPC_REPLY_MODE', 'shared'),
        'per_request_ttl' => (int) env('QUEUE_RPC_PER_REQUEST_TTL', 60),
    ],

    // HMAC-подпись correlationId
    'hmac' => [
        'secret'    => env('QUEUE_HMAC_SECRET', ''),
        'algorithm' => 'sha256',
    ],

    // Circuit Breaker для RPC
    'circuit_breaker' => [
        'enabled'           => (bool) env('QUEUE_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('QUEUE_CIRCUIT_BREAKER_FAILURES', 5),
        'reset_timeout'     => (int) env('QUEUE_CIRCUIT_BREAKER_RESET', 30),
    ],

    // Allowlist job-классов
    'allowed_jobs' => [],
];
```

### Режимы маршрутизации ответов

| Режим | `QUEUE_RPC_REPLY_MODE` | Описание |
|---|---|---|
| `shared` | `shared` | Общая response-очередь сервиса (по умолчанию, backward compatible) |
| `per_request` | `per_request` | Отдельная временная очередь на каждый RPC-запрос (изоляция) |
| `direct_reply_to` | `direct_reply_to` | **Experimental** — сейчас fallback на `per_request` |

> **per_request** требует драйвер `RabbitMQQueue`. Создаёт временную очередь с `x-expires` и `x-message-ttl` (настраивается через `QUEUE_RPC_PER_REQUEST_TTL`).

### Allowlist job-классов

> [!IMPORTANT]
> По умолчанию разрешён вызов любого алиаса/FQCN из контейнера. В production настройте `allowed_jobs`.

```php
'allowed_jobs' => [
    // Маппинг алиаса → FQCN
    'TASK_CHECK_TARIFF' => \App\Jobs\CheckUserTariffJob::class,
    // Разрешить алиас как есть (должен быть забинжен в контейнере)
    'TRIGGER_EVENT'     => null,
],
```

Когда `allowed_jobs` не пуст — разрешены **только** ключи из массива.

### HMAC-подпись

По умолчанию **отключена** (пустой `secret`). Для включения:

```env
QUEUE_HMAC_SECRET=your-shared-secret-here
```

Один и тот же секрет должен быть на **всех** RPC-сервисах. Формат подписанного ID: `{correlationId}.{hmac_hex}`.

### Circuit Breaker

После N последовательных таймаутов circuit открывается и RPC-вызовы мгновенно падают с `CircuitBreakerOpenException`. Через `reset_timeout` секунд — переход в half-open (одна пробная попытка).

```env
QUEUE_CIRCUIT_BREAKER_ENABLED=true
QUEUE_CIRCUIT_BREAKER_FAILURES=5
QUEUE_CIRCUIT_BREAKER_RESET=30
```

---

## Использование

### RPC — ожидание ответа

```php
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;

$response = app(ExternalJob::class)->getResponse(
    message: new ExternalMessage(
        name: 'TASK_CHECK_TARIFF',
        params: ['userId' => 12345]
    ),
    queue: 'billing-service:request'
);

// $response — массив данных от сервиса-получателя
```

**Механика:** `getResponse` генерирует `correlationId`, публикует сообщение, затем через `ResponseWorker` (Fiber) слушает response-очередь до получения ответа с тем же `correlationId` или таймаута.

### Fire-and-Forget — без ожидания

```php
app(ExternalJob::class)->sendMessage(
    message: new ExternalMessage(
        name: 'EVENT_TARIFF_UPGRADED',
        params: ['userId' => 12345, 'tariff' => 'Premium']
    ),
    queue: 'notification-service:request'
);
```

### Fluent Builder

```php
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;

$message = ExternalMessage::make('TASK_CHECK_TARIFF')
    ->param('userId', 12345)
    ->param('region', 'eu')
    ->handler('external')
    ->build();
```

Builder **immutable** — каждый вызов возвращает новый экземпляр.

### Получение задач и отправка ответа

На сервисе-получателе связываем алиас с классом в `ServiceProvider`:

```php
app()->bind('TASK_CHECK_TARIFF', \App\Jobs\CheckUserTariffJob::class);
```

Job-класс:

```php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\ResponseMessage;

class CheckUserTariffJob implements ShouldQueue
{
    public function __construct(private readonly int $userId) {}

    public function handle(ExternalJob $externalJob): void
    {
        $tariff = ['id' => 1, 'name' => 'Premium'];

        // Ручная отправка ответа
        $responseQueue = $this->job->payload()['data'][ExternalJob::JOB_RESPONSE] ?? null;

        if (!empty($responseQueue)) {
            $externalJob->sendMessage(
                message: new ResponseMessage(
                    success: true,
                    data: $tariff,
                    metadata: ['process_time' => 0.1]
                ),
                queue: $responseQueue,
                correlationId: $this->job->getJobId()
            );
        }
    }
}
```

### Event Broadcasting

Отправка одного сообщения в несколько очередей (broadcast):

```php
$externalJob = app(ExternalJob::class);
$externalJob->addSubscriber('service-a:request');
$externalJob->addSubscriber('service-b:request');

$externalJob->sendEvent(
    new ExternalMessage(
        name: 'EVENT_USER_CREATED',
        params: ['userId' => 42]
    )
);
```

### DTO для параметров

Пакет поддерживает типизированные DTO через `DtoInterface`:

```php
use AgelxNash\LaravelQueuePayload\Contracts\Queue\DtoInterface;

class CheckTariffDto implements DtoInterface
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $region = null,
    ) {}
}
```

Отправка:

```php
$message = ExternalMessage::make('TASK_CHECK_TARIFF')
    ->param('payload', new CheckTariffDto(userId: 12345, region: 'eu'))
    ->build();
```

На стороне получателя DTO автоматически восстанавливается и передаётся в конструктор Job. Поддерживается рекурсивная десериализация вложенных DTO.

### Кастомная сериализация при dispatch()

Для прозрачного вызова `Job::dispatch()` с автоматической конвертацией в кроссплатформенный JSON создайте кастомный коннектор. Подробности — в [docs/usage-rpc.md](docs/usage-rpc.md#кастомная-сериализация-при-dispatch).

---

## Observability

Пакет генерирует Laravel-события для мониторинга:

| Событие | Когда |
|---|---|
| `MessageSent` | Сообщение опубликовано в очередь |
| `ResponseReceived` | Ответ получен (с `waitTime`) |
| `ResponseTimeout` | Превышен таймаут ожидания |
| `CircuitBreakerOpened` | Circuit Breaker перешёл в open |

Подписка:

```php
Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseReceived::class, function ($event) {
    Log::info('RPC response', [
        'correlationId' => $event->correlationId,
        'waitTime'      => $event->waitTime,
        'queue'         => $event->queue,
    ]);
});
```

---

## Безопасность

- **Allowlist job** — ограничение списка вызываемых классов
- **HMAC-подпись** — защита correlationId от подделки
- **Валидация параметров** — ответственность вашей Job (используйте type-hinted конструкторы)
- **TLS + ACL RabbitMQ** — рекомендуется для production

Подробнее: [docs/security.md](docs/security.md)

---

## Миграция shared → per_request

1. Убедитесь, что используется драйвер `rabbitmq` (пакет `vladimir-yuldashev/laravel-queue-rabbitmq`)
2. Задайте `QUEUE_RPC_REPLY_MODE=per_request` в `.env`
3. Настройте `QUEUE_RPC_PER_REQUEST_TTL` (по умолчанию 60 сек)
4. Удалите или оставьте пустым соединение `response` в `config/queue.php` — в режиме `per_request` временные очереди создаются динамически

> **direct_reply_to** — experimental, сейчас fallback на `per_request`. Не рекомендуется для production.

Подробнее: [docs/migration.md](docs/migration.md)

---

## Troubleshooting

### Response timeout exceeded

**Причина:** Сервис-получатель не отправил ответ в течение таймаута.

**Решение:**
1. Увеличьте `QUEUE_RESPONSE_TIMEOUT`
2. Проверьте, что `php artisan queue:work request` запущен
3. Проверьте логи сервиса-получателя

### Job 'X' is not in the allowed jobs list

**Причина:** Job не найден в `allowed_jobs`.

**Решение:** Добавьте алиас в конфиг или очистите allowlist (пустой массив).

### per_request reply mode requires RabbitMQQueue driver

**Причина:** Режим `per_request`/`direct_reply_to` требует драйвер RabbitMQ.

**Решение:** Используйте `driver => 'rabbitmq'` в `config/queue.php` или переключитесь на `shared`.

### Circuit breaker is open

**Причина:** Превышен порог ошибок RPC-вызовов.

**Решение:** Дождитесь `reset_timeout` или устраните причину таймаутов. Для отключения: `QUEUE_CIRCUIT_BREAKER_ENABLED=false`.

### Response worker shutdown requested

**Причина:** Получен сигнал SIGTERM/SIGINT (graceful shutdown).

**Решение:** Ожидаемое поведение. RPC-вызов завершится с `MaxAttemptsQueueException`.

Подробнее: [docs/troubleshooting.md](docs/troubleshooting.md)

---

## Документация / Wiki

| Документ | Описание |
|---|---|
| [docs/README.md](docs/README.md) | Индекс документации |
| [docs/architecture.md](docs/architecture.md) | Архитектура пакета, компоненты, потоки данных |
| [docs/configuration.md](docs/configuration.md) | Полное описание всех настроек и ENV-переменных |
| [docs/usage-rpc.md](docs/usage-rpc.md) | RPC, Fire-and-Forget, Builder, DTO, кастомная сериализация |
| [docs/usage-events.md](docs/usage-events.md) | Event Broadcasting, триггер событий через Job-обёртку |
| [docs/security.md](docs/security.md) | HMAC, Allowlist, валидация, рекомендации |
| [docs/observability.md](docs/observability.md) | Observability Events, мониторинг, логирование |
| [docs/testing.md](docs/testing.md) | Тестирование, Docker Compose, моки |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Типичные ошибки и решения |
| [docs/migration.md](docs/migration.md) | Миграция shared → per_request/direct_reply_to |

### Подготовка GitHub Wiki

Для генерации wiki-совместимого набора страниц из `docs/`:

```bash
bash scripts/sync-wiki.sh
```

По умолчанию результат будет создан в директории `.wiki/`.

Можно указать свой путь:

```bash
bash scripts/sync-wiki.sh /path/to/wiki-export
```

---

## Лицензия

MIT License. Подробнее — [LICENSE](LICENSE).
