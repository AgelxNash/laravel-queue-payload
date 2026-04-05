# Архитектура пакета

## Обзор

`laravel-queue-payload` — прослойка между Laravel Queue и RabbitMQ, которая трансформирует стандартный PHP-сериализованный payload в кроссплатформенный JSON-формат и добавляет поддержку RPC (Request-Response) поверх очередей.

## Компоненты

### Ядро очереди

| Класс | Назначение |
|---|---|
| `ExternalJob` | Главный фасад: `sendMessage()`, `getResponse()`, `sendEvent()`, `addSubscriber()` |
| `ExternalHandler` | Обработчик входящих задач из очереди (наследует `CallQueuedHandler`). Триггер — `"job": "external"` |
| `ExternalMessage` | DTO сообщения: `name` (алиас/FQCN), `params`, `handler` |
| `ExternalMessageBuilder` | Fluent Builder для `ExternalMessage` (immutable) |
| `ResponseMessage` | DTO для ответа: `success`, `data`, `metadata` |

### Response Worker (RPC)

| Класс | Назначение |
|---|---|
| `ResponseWorker` | Ожидание ответа через Fiber-корутину. Использует `Illuminate\Queue\Worker` |
| `ResponseHandler` | Обработчик pop-колбэка: фильтрация по `correlationId`, HMAC-верификация |

### Безопасность

| Класс | Назначение |
|---|---|
| `HmacSigner` | HMAC-подпись/верификация `correlationId` (SHA-256 по умолчанию) |
| `CircuitBreaker` | Circuit Breaker для RPC: closed → open → half-open |

### DTO

| Класс | Назначение |
|---|---|
| `DtoSerializer` | Сериализация/десериализация DTO с поддержкой вложенности |
| `DtoInterface` | Маркерный интерфейс для типизированных DTO |

### События

| Класс | Когда срабатывает |
|---|---|
| `MessageSent` | Сообщение опубликовано в очередь |
| `ResponseReceived` | Ответ получен (с `waitTime`) |
| `ResponseTimeout` | Превышен таймаут ожидания |
| `CircuitBreakerOpened` | Circuit Breaker перешёл в open |

### Исключения

| Класс | Когда выбрасывается |
|---|---|
| `MaxAttemptsQueueException` | Таймаут ответа или graceful shutdown |
| `CircuitBreakerOpenException` | Circuit Breaker в состоянии open |

### Перечисления

| Класс | Значения |
|---|---|
| `QueueConnections` | `sync`, `request`, `response` |

## Потоки данных

### RPC (Request-Response)

```
[Service A]                          [Service B]
    |                                    |
    |  ExternalJob::getResponse()        |
    |  ── generate correlationId         |
    |  ── HMAC sign (если включён)       |
    |  ── resolve response queue         |
    |  ── pushRaw(payload) ────────────> | queue: billing-service:request
    |                                    |  ExternalHandler::fire()
    |                                    |  ── getCommand() → resolve job
    |                                    |  ── DtoSerializer::decodeParams()
    |                                    |  ── Job::handle()
    |                                    |  ── ExternalJob::sendMessage(ResponseMessage)
    |  <───────────────────────────────── | queue: auth-clients:response
    |  ResponseWorker::waitResponse()    |
    |  ── ResponseHandler::__invoke()    |
    |  ── HMAC verify (если включён)     |
    |  ── match correlationId            |
    |  ── return response                |
    |                                    |
```

### Fire-and-Forget

```
[Service A]                          [Service B]
    |                                    |
    |  ExternalJob::sendMessage()        |
    |  ── pushRaw(payload) ────────────> | queue: notification-service:request
    |  ── return (не ждёт ответа)        |  ExternalHandler::fire()
    |                                    |  ── Job::handle()
    |                                    |  (ответ не отправляется)
```

### Event Broadcasting

```
[Service A]                          [Service B]    [Service C]
    |                                    |              |
    |  addSubscriber('B:request')        |              |
    |  addSubscriber('C:request')        |              |
    |  ExternalJob::sendEvent()          |              |
    |  ── pushRaw(payload) ────────────> |              |
    |  ── pushRaw(payload) ──────────────────────────> |
    |                                    |              |
```

## Режимы маршрутизации ответов

### shared (по умолчанию)

- Одна общая response-очередь на сервис (например, `auth-clients:response`)
- Все RPC-ответы приходят в одну очередь
- `ResponseHandler` фильтрует сообщения по `correlationId`
- Работает с любым драйвером очереди

### per_request

- Отдельная временная очередь на каждый RPC-запрос
- Имя: `{app-slug}.rpc.reply.{correlationId}`
- Аргументы: `x-expires` и `x-message-ttl` (настраиваются через `QUEUE_RPC_PER_REQUEST_TTL`)
- Автоматическая очистка после получения ответа
- **Требует** драйвер `RabbitMQQueue` (`vladimir-yuldashev/laravel-queue-rabbitmq`)

### direct_reply_to (experimental)

- Сейчас fallback на `per_request`
- Не рекомендуется для production

## Dependency Injection (ServiceProvider)

```
ExternalJobInterface (singleton)
  ├── QueueContract (bind → Factory::connection('request'))
  ├── ResponseWorkerInterface (bind)
  │     ├── Worker (Laravel)
  │     ├── ResponseHandler (factory → новый на каждый вызов)
  │     │     └── HmacSigner (singleton)
  │     ├── WorkerOptions
  │     └── CircuitBreaker (опционально)
  └── HmacSigner (singleton)

ExternalHandler::NAME → ExternalHandler::class (bind)
```

## Ключевые константы

| Константа | Значение | Где используется |
|---|---|---|
| `ExternalHandler::NAME` | `'external'` | Ключ `job` в payload |
| `ExternalJob::JOB_CLASS` | `'type'` | Алиас/FQCN job в `data` |
| `ExternalJob::JOB_PARAMS` | `'params'` | Параметры job в `data` |
| `ExternalJob::JOB_RESPONSE` | `'response'` | Имя response-очереди в `data` |
