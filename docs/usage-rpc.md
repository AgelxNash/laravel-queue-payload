# Использование: RPC и сообщения

## RPC — ожидание ответа

Метод `ExternalJob::getResponse()` отправляет сообщение и блокирует выполнение до получения ответа или таймаута.

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
```

### Подпись метода

```php
public function getResponse(
    MessageInterface $message,
    string $queue,
    ResponsePrepareInterface|callable|null $prepare = null,
): mixed
```

- `$message` — сообщение (`ExternalMessage` или любой `MessageInterface`)
- `$queue` — имя целевой очереди (например, `billing-service:request`)
- `$prepare` — колбэк для извлечения данных из ответа (по умолчанию берёт `data.params`)

### Механика

1. Генерируется `correlationId` (UUID v4 через `Str::uuid()`)
2. Подписывается HMAC (если включён)
3. Определяется response-очередь (зависит от `reply.mode`)
4. Сообщение публикуется через `pushRaw()`
5. `ResponseWorker` слушает response-очередь через Fiber
6. При совпадении `correlationId` — ответ возвращается
7. При таймауте — выбрасывается `MaxAttemptsQueueException`
8. Временная per-request очередь удаляется (best-effort)

## Fire-and-Forget — без ожидания

Метод `ExternalJob::sendMessage()` публикует сообщение и сразу возвращает управление.

```php
app(ExternalJob::class)->sendMessage(
    message: new ExternalMessage(
        name: 'EVENT_TARIFF_UPGRADED',
        params: ['userId' => 12345, 'tariff' => 'Premium']
    ),
    queue: 'notification-service:request'
);
```

### Подпись метода

```php
public function sendMessage(
    MessageInterface $message,
    string $queue,
    string|null $correlationId = null,
): void
```

- `$correlationId` — опциональный ID (по умолчанию генерируется внутри `createPayload`)

## Fluent Builder

`ExternalMessageBuilder` позволяет строить сообщения цепочечными вызовами.

```php
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;

$message = ExternalMessage::make('TASK_CHECK_TARIFF')
    ->param('userId', 12345)
    ->param('region', 'eu')
    ->handler('external')
    ->build();
```

### Доступные методы

| Метод | Описание |
|---|---|
| `make(string $name)` | Создать builder с именем задачи |
| `param(string $key, mixed $value)` | Добавить один параметр |
| `params(array $params)` | Установить все параметры |
| `handler(string $handler)` | Установить имя обработчика (ключ `job` в payload) |
| `build()` | Собрать `ExternalMessage` |
| `toMessage()` | Алиас для `build()` |

### Immutable

Builder **immutable** — каждый метод возвращает новый экземпляр:

```php
$base = ExternalMessage::make('TASK_CHECK_TARIFF');

$msg1 = $base->param('userId', 1)->build();
$msg2 = $base->param('userId', 2)->build(); // $base не изменён
```

## Получение задач и отправка ответа

### Регистрация алиаса

В `ServiceProvider` сервиса-получателя:

```php
app()->bind('TASK_CHECK_TARIFF', \App\Jobs\CheckUserTariffJob::class);
```

### Job-класс

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

        // Извлекаем response-очередь из payload
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

### ResponseMessage

```php
new ResponseMessage(
    success: true,       // bool — статус выполнения
    data: $result,       // mixed — данные ответа
    metadata: []         // array — метаданные (время, версия и т.д.)
)
```

## DTO для параметров

### Создание DTO

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

### Отправка с DTO

```php
$message = ExternalMessage::make('TASK_CHECK_TARIFF')
    ->param('payload', new CheckTariffDto(userId: 12345, region: 'eu'))
    ->build();
```

### Сериализация

`DtoSerializer` автоматически:

- **Кодирует** DTO в `{'__dto_class': '...', '__dto_data': {...}}`
- **Декодирует** обратно в объект при получении
- Поддерживает **рекурсивную** десериализацию вложенных DTO

### Требования к DTO

- Реализует `DtoInterface` (маркерный интерфейс)
- Имеет публичный конструктор с именованными параметрами
- Рекомендуется `readonly` properties (immutable)

## Кастомная сериализация при dispatch()

Для прозрачного вызова `Job::dispatch()` с автоматической конвертацией в кроссплатформенный JSON:

### 1. Кастомная очередь

```php
namespace App\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class CustomRabbitMQQueue extends RabbitMQQueue
{
    protected function createObjectPayload($job, $queue)
    {
        if (method_exists($job, 'getExternalPayload')) {
            return [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'job'  => ExternalHandler::NAME,
                'data' => [
                    'type'   => get_class($job),
                    'params' => $job->getExternalPayload(),
                ],
            ];
        }

        return parent::createObjectPayload($job, $queue);
    }
}
```

### 2. Кастомный коннектор

```php
namespace App\Queue;

use Illuminate\Contracts\Queue\Queue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connection\ConnectionFactory;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;
use Illuminate\Queue\Events\WorkerStopping;

class CustomRabbitMQConnector extends RabbitMQConnector
{
    public function connect(array $config): Queue
    {
        $connection = ConnectionFactory::make($config);

        $queueConfig = \VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfigFactory::make($config);
        $queue = new CustomRabbitMQQueue($queueConfig);
        $queue->setConnection($connection);

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->close();
        });

        return $queue;
    }
}
```

### 3. Регистрация коннектора

```php
namespace App\Providers;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use App\Queue\CustomRabbitMQConnector;

class AppServiceProvider extends ServiceProvider
{
    public function boot(QueueManager $manager): void
    {
        $manager->addConnector('custom-rabbitmq', function () {
            return new CustomRabbitMQConnector($this->app['events']);
        });
    }
}
```

### 4. Job с `getExternalPayload()`

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckUserTariffJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $userId) {}

    public function getExternalPayload(): array
    {
        return ['userId' => $this->userId];
    }
}
```

### 5. Обновите конфиг

Замените `'driver' => 'rabbitmq'` на `'driver' => 'custom-rabbitmq'` в `config/queue.php`.

Теперь `CheckUserTariffJob::dispatch($userId)` автоматически отправляет кроссплатформенный JSON.

## Динамическое изменение обработчика

По умолчанию `"job": "external"`. Можно переопределить:

```php
$message = new ExternalMessage(
    name: 'TASK_CHECK_TARIFF',
    params: ['userId' => 12345],
    handler: 'go-billing-handler'
);
```

Или через Builder:

```php
ExternalMessage::make('TASK_CHECK_TARIFF')
    ->handler('go-billing-handler')
    ->build();
```

## Переопределение структуры payload

Для non-Laravel получателей, которые не ожидают ключ `"job"`, можно унаследовать `ExternalJob` и переопределить `createPayload()`:

```php
namespace App\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Contracts\Queue\MessageInterface;

class CustomPayloadJob extends ExternalJob
{
    protected function createPayload(MessageInterface $message, string|null $correlationId = null): string
    {
        return json_encode([
            'id'      => $correlationId,
            'task'    => $message->getHandler(),
            'payload' => $message->getParams(),
        ]);
    }
}
```

Использование: `app(CustomPayloadJob::class)->sendMessage(...)`.
