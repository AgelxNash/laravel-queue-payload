# Тестирование

## Обзор

Пакет покрыт тремя уровнями тестов:

| Уровень | Директория | Описание |
|---|---|---|
| Unit | `tests/Unit/` | Изолированные тесты отдельных классов |
| Feature | `tests/Feature/` | Тесты взаимодействия компонентов |
| Integration | `tests/Integration/` | Тесты с реальным RabbitMQ (Docker Compose) |

## Запуск тестов

### Все тесты

```bash
composer test
# или
vendor/bin/phpunit
```

### Только Integration-тесты

```bash
composer test:integration
# или
vendor/bin/phpunit --testsuite Integration
```

### С покрытием

```bash
vendor/bin/phpunit --coverage-html coverage/
```

## Docker Compose для Integration-тестов

Файл `docker-compose.tests.yml` поднимает RabbitMQ для интеграционных тестов:

```bash
docker compose -f docker-compose.tests.yml up -d
vendor/bin/phpunit --testsuite Integration
docker compose -f docker-compose.tests.yml down
```

## Моки и стабы

### Мокирование QueueContract

Для Unit-тестов `ExternalJob` мокируется `QueueContract`:

```php
$queueMock = $this->createMock(QueueContract::class);
$queueMock->expects($this->once())
    ->method('pushRaw')
    ->with($this->callback(function ($payload) {
        $data = json_decode($payload, true);
        return $data['job'] === 'external';
    }));
```

### Мокирование ResponseWorkerInterface

```php
$workerMock = $this->createMock(ResponseWorkerInterface::class);
$workerMock->method('waitResponse')
    ->willReturn(['success' => true, 'data' => ['id' => 1]]);
```

## TestCase

Базовый `TestCase` (`tests/TestCase.php`) расширяет `Orchestra\Testbench\TestCase` и предоставляет:

- Загрузку пакета через `getPackageProviders()`
- Настройку конфигурации через `getEnvironmentSetUp()`
- Общие фикстуры

## Фикстуры

Директория `tests/Fixtures/` содержит тестовые DTO и Job-классы:

- Тестовые DTO для проверки сериализации
- Тестовые Job-классы для проверки allowlist
- Тестовые обработчики

## Статический анализ

### PHPStan / Larastan

```bash
composer analitics:phpstan
# или
vendor/bin/phpstan analyse --memory-limit=-1
```

### PHP CodeSniffer

```bash
composer app:check:phpcs
# или
vendor/bin/phpcs -s -p --no-cache
```

### Laravel Pint

```bash
composer app:check:pint
# или
vendor/bin/pint --test -vvv
```

## CI/CD

GitHub Actions (`.github/workflows/`) запускают:

1. PHPStan (статический анализ)
2. PHPUnit (unit + feature тесты)
3. Pint (кодстайл)
4. Integration-тесты с Docker Compose
