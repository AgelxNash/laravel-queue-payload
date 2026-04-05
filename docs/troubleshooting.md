# Troubleshooting

## Response timeout exceeded

**Сообщение:** `MaxAttemptsQueueException: Response timeout exceeded [correlationId=..., queue=...]`

### Причины

1. Сервис-получатель не запущен (`php artisan queue:work request`)
2. Сервис-получатель упал с ошибкой и не отправил ответ
3. Таймаут слишком мал для длительных операций
4. Ошибка соединения с RabbitMQ на стороне получателя

### Решение

1. **Увеличьте таймаут:**

```env
QUEUE_RESPONSE_TIMEOUT=120
```

2. **Проверьте воркер получателя:**

```bash
php artisan queue:work request -v
```

3. **Проверьте логи получателя** на наличие ошибок выполнения Job

4. **Проверьте RabbitMQ:**

```bash
rabbitmqctl list_queues name messages consumers
```

---

## Job 'X' is not in the allowed jobs list

**Сообщение:** `RuntimeException: Job 'X' is not in the allowed jobs list.`

### Причина

Job-класс не найден в `allowed_jobs` конфиге.

### Решение

**Вариант 1:** Добавьте алиас в конфиг:

```php
// config/agelxnash-queue.php
'allowed_jobs' => [
    'TASK_X' => \App\Jobs\TaskXJob::class,
],
```

**Вариант 2:** Очистите allowlist (разрешить все):

```php
'allowed_jobs' => [],
```

---

## per_request reply mode requires RabbitMQQueue driver

**Сообщение:** `RuntimeException: per_request reply mode requires RabbitMQQueue driver, got ...`

### Причина

Режим `per_request` или `direct_reply_to` требует драйвер `RabbitMQQueue` (пакет `vladimir-yuldashev/laravel-queue-rabbitmq`).

### Решение

**Вариант 1:** Используйте драйвер `rabbitmq`:

```php
// config/queue.php
'connections' => [
    'request' => [
        'driver' => 'rabbitmq',
        // ...
    ],
],
```

**Вариант 2:** Переключитесь на режим `shared`:

```env
QUEUE_RPC_REPLY_MODE=shared
```

---

## Circuit breaker is open

**Сообщение:** `CircuitBreakerOpenException: Circuit breaker is open [failures=..., threshold=..., retryAfter=...]`

### Причина

Превышен порог последовательных ошибок RPC-вызовов (`QUEUE_CIRCUIT_BREAKER_FAILURES`).

### Решение

1. **Дождитесь автоматического восстановления** через `QUEUE_CIRCUIT_BREAKER_RESET` секунд
2. **Устраните причину таймаутов** (см. "Response timeout exceeded")
3. **Временно отключите Circuit Breaker:**

```env
QUEUE_CIRCUIT_BREAKER_ENABLED=false
```

---

## Response worker shutdown requested

**Сообщение:** `MaxAttemptsQueueException: Response worker shutdown requested [correlationId=..., queue=...]`

### Причина

Получен сигнал `SIGTERM` или `SIGINT` (graceful shutdown PHP-FPM, перезапуск контейнера).

### Решение

Это **ожидаемое поведение**. RPC-вызов завершится с исключением. Повторите запрос после перезапуска.

---

## Ответ не приходит, но Job выполнен

### Возможные причины

1. **Не совпадает correlationId:**
   - Проверьте, что ответ отправляется с тем же `correlationId`, что и запрос
   - Используйте `$this->job->getJobId()` для получения ID

2. **Неверная response-очередь:**
   - Проверьте, что ответ отправляется в правильную очередь
   - Извлеките очередь из payload: `$this->job->payload()['data'][ExternalJob::JOB_RESPONSE]`

3. **HMAC-подпись невалидна:**
   - Убедитесь, что `QUEUE_HMAC_SECRET` одинаков на всех сервисах
   - Проверьте алгоритм (`hmac.algorithm`)

4. **Режим маршрутизации:**
   - В режиме `per_request` временная очередь может истечь по TTL
   - Увеличьте `QUEUE_RPC_PER_REQUEST_TTL`

### Диагностика

Подпишитесь на события для отладки:

```php
Event::listen(\AgelxNash\LaravelQueuePayload\Events\MessageSent::class, fn ($e) => dump('Sent:', $e));
Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseReceived::class, fn ($e) => dump('Received:', $e));
Event::listen(\AgelxNash\LaravelQueuePayload\Events\ResponseTimeout::class, fn ($e) => dump('Timeout:', $e));
```

---

## Ошибки соединения с RabbitMQ

### Симптомы

- `MaxAttemptsQueueException` с сообщением об ошибке соединения
- Воркер не может подключиться к RabbitMQ

### Решение

1. **Проверьте подключение:**

```bash
docker exec rabbitmq rabbitmqctl status
```

2. **Проверьте credentials:**

```php
// config/queue.php
'hosts' => [[
    'host'     => env('RABBITMQ_HOST', 'rabbit'),
    'port'     => env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
]],
```

3. **Проверьте vhost:**

```bash
rabbitmqctl list_vhosts
```

---

## Общие рекомендации по диагностике

1. **Включите verbose-логирование** воркера: `php artisan queue:work request -vvv`
2. **Проверьте payload** сообщения в RabbitMQ Management UI
3. **Убедитесь**, что алиас job забинжен в контейнере получателя
4. **Проверьте**, что оба сервиса используют одну версию пакета
