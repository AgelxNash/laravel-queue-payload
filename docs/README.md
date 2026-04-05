# Документация Laravel Queue Payload

Полная документация пакета для межсервисного взаимодействия через RabbitMQ.

## Индекс документов

| Документ | Описание |
|---|---|
| [Архитектура](architecture.md) | Компоненты пакета, потоки данных, роли сервисов |
| [Конфигурация](configuration.md) | Все настройки, ENV-переменные, режимы маршрутизации |
| [Использование: RPC](usage-rpc.md) | RPC, Fire-and-Forget, Fluent Builder, DTO, кастомная сериализация |
| [Использование: Events](usage-events.md) | Event Broadcasting, триггер событий через Job-обёртку |
| [Безопасность](security.md) | HMAC, Allowlist, валидация, рекомендации |
| [Observability](observability.md) | Events мониторинга, логирование, метрики |
| [Тестирование](testing.md) | Unit/Feature/Integration тесты, Docker Compose |
| [Troubleshooting](troubleshooting.md) | Типичные ошибки и решения |
| [Миграция](migration.md) | Переход shared → per_request/direct_reply_to |

## Быстрый старт

1. [Установка](../README.md#установка)
2. [Конфигурация RabbitMQ](../README.md#конфигурация-rabbitmq)
3. [Первый RPC-вызов](../README.md#rpc--ожидание-ответа)
