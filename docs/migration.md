# Миграция: shared → per_request / direct_reply_to

## Обзор

По умолчанию пакет использует режим `shared` — общую response-очередь для всех RPC-ответов сервиса. Режимы `per_request` и `direct_reply_to` создают отдельные временные очереди для каждого RPC-запроса, обеспечивая лучшую изоляцию.

## Сравнение режимов

| Характеристика | `shared` | `per_request` | `direct_reply_to` |
|---|---|---|---|
| Очередь ответов | Одна общая | Временная на запрос | Временная на запрос |
| Изоляция | Нет (фильтрация по correlationId) | Полная | Полная |
| Требует RabbitMQQueue | Нет | **Да** | **Да** |
| Статус | Stable | Stable | **Experimental** |
| Настройка response-очереди | В `config/queue.php` | Динамически | Динамически |

## Миграция shared → per_request

### Шаг 1: Проверьте драйвер

Убедитесь, что используется драйвер `rabbitmq` (пакет `vladimir-yuldashev/laravel-queue-rabbitmq`):

```php
// config/queue.php
'connections' => [
    'request' => [
        'driver' => 'rabbitmq', // Должен быть rabbitmq, не sync/redis/database
        // ...
    ],
],
```

Если используется другой драйвер — миграция невозможна. Оставайтесь на `shared`.

### Шаг 2: Обновите ENV

```env
QUEUE_RPC_REPLY_MODE=per_request
QUEUE_RPC_PER_REQUEST_TTL=60
```

### Шаг 3: Настройте TTL

`QUEUE_RPC_PER_REQUEST_TTL` — время жизни временной очереди в секундах.

- Должен быть **больше** `QUEUE_RESPONSE_TIMEOUT`
- Рекомендуемое значение: `QUEUE_RESPONSE_TIMEOUT + 30`

```env
QUEUE_RESPONSE_TIMEOUT=60
QUEUE_RPC_PER_REQUEST_TTL=90
```

### Шаг 4: Соединение response (опционально)

В режиме `per_request` соединение `response` в `config/queue.php` больше не используется для RPC. Его можно:

- **Удалить** — если не используется для других целей
- **Оставить** — для обратной совместимости

```php
// Можно удалить или оставить пустым
QueueConnections::RESPONSE->value => [
    'driver' => 'rabbitmq',
    'queue'  => 'my-service:' . QueueConnections::RESPONSE->value,
],
```

### Шаг 5: Протестируйте

```bash
# Запустите тесты
composer test

# Проверьте RPC-вызовы в staging
```

## Миграция shared → direct_reply_to

> **Внимание:** `direct_reply_to` — experimental режим. В текущей версии это fallback на `per_request`. Не рекомендуется для production.

```env
QUEUE_RPC_REPLY_MODE=direct_reply_to
QUEUE_RPC_PER_REQUEST_TTL=60
```

Все остальные шаги идентичны миграции на `per_request`.

## Откат per_request → shared

```env
QUEUE_RPC_REPLY_MODE=shared
```

Временные очереди будут автоматически удалены по истечении TTL.

## Что меняется при миграции

### Имена очередей

**shared:**
- Ответы приходят в: `my-service:response`

**per_request:**
- Ответы приходят в: `my-service.rpc.reply.{correlationId}`
- Пример: `my-service.rpc.reply.550e8400-e29b-41d4-a716-446655440000`

### Аргументы временных очередей

```
x-expires:     {TTL}ms   // Автоудаление очереди без потребителей
x-message-ttl: {TTL}ms   // TTL сообщений в очереди
durable:       false     // Очередь не сохраняется при перезапуске RabbitMQ
auto_delete:   true      // Автоудаление при отключении последнего потребителя
```

### Очистка

После получения ответа (или таймаута) пакет пытается удалить временную очередь (best-effort). Если удаление не удалось — очередь удалится автоматически по `x-expires`.

## Известные ограничения

1. **Только RabbitMQQueue:** `per_request` и `direct_reply_to` требуют драйвер `vladimir-yuldashev/laravel-queue-rabbitmq`
2. **TTL в миллисекундах:** Значение `QUEUE_RPC_PER_REQUEST_TTL` (секунды) умножается на 1000 для RabbitMQ
3. **Best-effort cleanup:** Ошибка удаления временной очереди не влияет на работу — очередь удалится по TTL
4. **direct_reply_to fallback:** В текущей версии `direct_reply_to` работает идентично `per_request`
