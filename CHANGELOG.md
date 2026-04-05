# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `ExternalJob::reply()` — удобный хелпер для отправки ответа с автоматическим извлечением `correlationId` и `responseQueue` из входящей задачи
- `ExternalMessage::make()` и `ExternalMessageBuilder` — Fluent Builder для создания сообщений с immutable chaining
- Allowlist job-классов через `config('agelxnash-queue.allowed_jobs')` — защита от произвольной инстанциации классов через payload очереди
- Circuit Breaker для RPC-вызовов — после N таймаутов мгновенный fail-fast с half-open recovery
- Observability hooks (Events): `MessageSent`, `ResponseReceived`, `ResponseTimeout`, `CircuitBreakerOpened`
- HMAC-подпись correlationId — опциональная защита от подделки ответов (по умолчанию отключена, `QUEUE_HMAC_SECRET`)
- Graceful shutdown через `pcntl_signal(SIGTERM/SIGINT)` в `ResponseWorker`
- Error handling для ошибок RabbitMQ — `try/catch` с retry до таймаута
- Integration-тесты с Docker Compose (`docker-compose.tests.yml`) — реальный цикл publish → consume → response
- Секции "Безопасность", "Troubleshooting" и "Лицензия" в README

### Changed
- `ResponseMessage` — конструктор с именованными параметрами `(bool $success, mixed $data, array $metadata)` вместо `func_get_args()`
- `ExternalJob::$connect` типизирован как `Illuminate\Contracts\Queue\Queue` вместо `RabbitMQQueueContract` — совместимость с v13/v14 rabbitmq-пакета
- UUID генерация через `Illuminate\Support\Str::uuid()` вместо `Symfony\Component\Uid\Uuid` — меньше зависимостей
- `ResponseWorker` клонирует `WorkerOptions` перед модификацией — защита от race condition
- `ResponseWorker` создаёт новый `ResponseHandler` на каждый `waitResponse()` через factory — защита от race condition в Octane/Swoole
- Конфиг: `sleep`/`max_wait` (микросекунды) заменены на `timeout` (секунды)
- Исключения используют `sprintf()` вместо `__()` — нет зависимости от lang-файлов
- `PACKAGE_NAME` изменён с `kd-services` на `laravel-queue-payload`

### Fixed
- `empty($correlationId)` → `$correlationId === null` — исправлен баг с `correlationId = "0"`
- `pushraw` → `pushRaw` — исправлена опечатка в вызове метода
- `release()` → `release(5)` — добавлен backoff для предотвращения livelock при mismatch correlationId
- Мёртвые параметры `$timeout`/`$maxLimit` удалены из `ResponseHandler`
- Удалена неиспользуемая зависимость `crell/serde`

### Removed
- `barryvdh/laravel-debugbar` из `require-dev`
- `brainmaestro/composer-git-hooks` из `require-dev` (перенесён в `suggest`)
- `platform.php` из `composer.json` config
- `version` из `composer.json` (Packagist берёт из Git-тегов)
- Git hooks из `extra.hooks`

### Security
- Добавлен allowlist job-классов — предотвращает произвольную инстанциацию через payload
- HMAC-подпись correlationId — опциональная защита от подделки ответов
- Документированы рекомендации по валидации входящих параметров
- Документированы рекомендации по защите correlation ID (TLS, ACL RabbitMQ)

---

## [1.3.19] — 2024-XX-XX

### Added
- Кроссплатформенный JSON payload для RabbitMQ очередей Laravel
- RPC (Request-Response) поверх очередей с поддержкой Fiber
- Fire-and-Forget отправка сообщений
- Кастомная сериализация при `dispatch()` через `CustomRabbitMQQueue`
- Поддержка триггера Events через Job-обёртку
- Динамическое изменение handler ключа
- Переопределение структуры payload для non-Laravel получателей
