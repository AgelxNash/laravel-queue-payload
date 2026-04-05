# Безопасность

## Обзор

Пакет предоставляет несколько уровней защиты для межсервисного взаимодействия через RabbitMQ.

## Allowlist job-классов

### Проблема

По умолчанию пакет разрешает вызов любого алиаса или FQCN из Laravel Container через payload очереди. Злоумышленник с доступом к очереди может отправить произвольный класс.

### Решение

Настройте `allowed_jobs` в `config/agelxnash-queue.php`:

```php
'allowed_jobs' => [
    // Маппинг алиаса → FQCN
    'TASK_CHECK_TARIFF' => \App\Jobs\CheckUserTariffJob::class,
    'TASK_SEND_EMAIL'   => \App\Jobs\SendEmailJob::class,

    // Разрешить алиас как есть (должен быть забинжен в контейнере)
    'TRIGGER_EVENT'     => null,
],
```

### Поведение

| Состояние | Поведение |
|---|---|
| Пустой массив (по умолчанию) | Разрешены все алиасы/FQCN |
| Непустой массив | Разрешены **только** ключи из массива |
| Значение `null` | Алиас разрешается через container |
| Значение FQCN string | Используется указанный класс |

### Ошибка при нарушении

```
RuntimeException: Job 'X' is not in the allowed jobs list.
Add it to config('agelxnash-queue.allowed_jobs') or clear the allowlist to allow all.
```

## HMAC-подпись correlationId

### Проблема

Злоумышленник может подделать ответ в response-очереди, указав чужой `correlationId`.

### Решение

HMAC-подпись добавляет криптографическую подпись к каждому `correlationId`.

### Настройка

```env
QUEUE_HMAC_SECRET=your-random-256-bit-secret-here
```

Один и тот же секрет должен быть на **всех** RPC-сервисах.

### Механика

1. **Отправка:** `correlationId` → `{correlationId}.{hmac_hex}`
2. **Получение:** верификация подписи через `hash_equals` (timing-safe)
3. **Невалидная подпись:** сообщение откладывается (`release(5)`), не считается ответом

### Алгоритм

По умолчанию `sha256`. Настраивается в конфиге:

```php
'hmac' => [
    'secret'    => env('QUEUE_HMAC_SECRET', ''),
    'algorithm' => 'sha256', // можно изменить на 'sha512' и т.д.
],
```

### Отключение

Пустой `secret` — signer работает как no-op (возвращает input без изменений).

## Валидация входящих параметров

### Проблема

Параметры из `data.params` передаются напрямую в конструктор Job через `container->make()`. Пакет **не валидирует** входящие данные.

### Рекомендации

1. **Type-hinted конструкторы:**

```php
public function __construct(
    private readonly int $userId,
    private readonly string $email,
) {}
```

2. **Валидация в `handle()`:**

```php
public function handle(): void
{
    if ($this->userId <= 0) {
        throw new InvalidArgumentException('Invalid userId');
    }
}
```

3. **Использование DTO:**

```php
class CheckTariffDto implements DtoInterface
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $region = null,
    ) {}
}
```

## Рекомендации для production

### RabbitMQ

- **TLS** для всех соединений с RabbitMQ
- **ACL** — ограничьте read/write доступ к очередям на уровне RabbitMQ
- **Vhost isolation** — разные сервисы в разных vhost

### Сеть

- RabbitMQ не должен быть доступен извне
- Используйте внутренние сети Docker/Kubernetes

### Мониторинг

- Подпишитесь на `ResponseTimeout` для обнаружения аномалий
- Логируйте `CircuitBreakerOpened` для отслеживания деградации сервисов
- Мониторьте `MessageSent` для аудита

## Уровни защиты

| Уровень | Механизм | Что защищает |
|---|---|---|
| Сеть | TLS + ACL | Перехват трафика |
| Доступ | RabbitMQ ACL | Несанкционированный доступ к очередям |
| Инстанциация | Allowlist jobs | Произвольный вызов классов |
| Целостность | HMAC-подпись | Подделка correlationId |
| Валидация | Type-hints + DTO | Некорректные параметры |
