<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Integration;

use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Базовый класс для integration-тестов с реальным RabbitMQ.
 *
 * Тесты пропускаются, если RabbitMQ недоступен (RABBITMQ_HOST не задан или соединение не устанавливается).
 *
 * Для запуска:
 *   docker compose -f docker-compose.tests.yml up -d
 *   RABBITMQ_HOST=127.0.0.1 vendor/bin/phpunit --testsuite Integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASSWORD') ?: 'guest';

        $app['config']->set('queue.connections.request', [
            'driver' => 'rabbitmq',
            'hosts' => [[
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'password' => $pass,
                'vhost' => '/',
            ]],
            'queue' => 'integration-test:request',
        ]);

        $app['config']->set('queue.connections.response', [
            'driver' => 'rabbitmq',
            'hosts' => [[
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'password' => $pass,
                'vhost' => '/',
            ]],
            'queue' => 'integration-test:response',
        ]);
    }

    /**
     * Проверяет доступность RabbitMQ. Если недоступен — пропускает тест.
     */
    protected function requireRabbitMQ(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASSWORD') ?: 'guest';

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $connection->close();
        } catch (Throwable $e) {
            $this->markTestSkipped(
                sprintf('RabbitMQ недоступен (%s:%s). Запустите: docker compose -f docker-compose.tests.yml up -d', $host, $port)
            );
        }
    }
}
