# Использование: Event Broadcasting

## Обзор

Пакет поддерживает отправку одного сообщения в несколько очередей (broadcast). Это полезно для событийных архитектур, где несколько сервисов должны реагировать на одно и то же событие.

## API

### addSubscriber()

Добавляет очередь в список подписчиков:

```php
$externalJob = app(\AgelxNash\LaravelQueuePayload\Queue\ExternalJob::class);
$externalJob->addSubscriber('service-a:request');
$externalJob->addSubscriber('service-b:request');
```

### sendEvent()

Отправляет сообщение всем подписчикам:

```php
$externalJob->sendEvent(
    new \AgelxNash\LaravelQueuePayload\Queue\ExternalMessage(
        name: 'EVENT_USER_CREATED',
        params: ['userId' => 42]
    )
);
```

Каждый подписчик получает **одинаковый payload** в свою очередь.

## Полный пример

```php
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;

$externalJob = app(ExternalJob::class);

// Регистрируем подписчиков
$externalJob->addSubscriber('notification-service:request');
$externalJob->addSubscriber('analytics-service:request');
$externalJob->addSubscriber('audit-service:request');

// Отправляем событие всем подписчикам
$externalJob->sendEvent(
    ExternalMessage::make('EVENT_USER_CREATED')
        ->param('userId', 42)
        ->param('email', 'user@example.com')
        ->build()
);
```

## Триггер событий через Job-обёртку

Laravel `CallQueuedHandler` вызывает только объекты с методом `handle()`. "Голые" Event-классы такого метода не имеют. Решение — Job-обёртка на стороне получателя.

### Получатель (Service B)

**1. Создайте FireEventJob:**

```php
namespace App\Jobs;

class FireEventJob
{
    public function __construct(
        private readonly string $eventName,
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        event(new $this->eventName(...$this->payload));
    }
}
```

**2. Зарегистрируйте алиас:**

```php
app()->bind('TRIGGER_EVENT', function ($app, $params) {
    return new \App\Jobs\FireEventJob($params['event'], $params['data']);
});
```

### Отправитель (Service A)

```php
app(ExternalJob::class)->sendMessage(
    message: new ExternalMessage(
        name: 'TRIGGER_EVENT',
        params: [
            'event' => 'App\\Events\\TariffUpgraded',
            'data'  => ['userId' => 12345, 'tariff' => 'Premium'],
        ]
    ),
    queue: 'notification-service:request'
);
```

## Важные замечания

- `sendEvent()` **не ожидает ответов** — это fire-and-forget
- Каждый подписчик обрабатывает сообщение независимо
- Если один подписчик упал — это не влияет на других
- Для RPC используйте `getResponse()` вместо `sendEvent()`
