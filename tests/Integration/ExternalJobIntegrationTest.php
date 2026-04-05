<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Integration;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\ExternalJobInterface;
use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;
use AgelxNash\LaravelQueuePayload\Tests\Integration\Fixtures\IntegrationCheckUserTariffJob;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Integration-тесты реального цикла publish → consume → response через RabbitMQ.
 *
 * Для запуска:
 *   docker compose -f docker-compose.tests.yml up -d
 *   RABBITMQ_HOST=127.0.0.1 vendor/bin/phpunit --testsuite Integration
 */
class ExternalJobIntegrationTest extends IntegrationTestCase
{
    /**
     * Реальный цикл: sendMessage → consume → reply → getResponse.
     *
     * Использует pcntl_fork для запуска воркера в дочернем процессе,
     * который обработает задачу и отправит ответ в response-очередь.
     */
    public function testFullRpcCycle(): void
    {
        $this->requireRabbitMQ();

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Расширение pcntl недоступно');
        }

        // Биндим алиас на integration-фикстуру с handle() + reply
        $this->app->bind('TASK_CHECK_TARIFF', IntegrationCheckUserTariffJob::class);

        /** @var ExternalJobInterface $externalJob */
        $externalJob = $this->app->make(ExternalJobInterface::class);

        // Запускаем воркер в дочернем процессе для обработки request-очереди
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Не удалось создать дочерний процесс');
        }

        if ($pid === 0) {
            // Child process: запускаем воркер для обработки request-очереди
            $laravel = __DIR__ . '/../../vendor/bin/laravel';
            if (!file_exists($laravel)) {
                $laravel = __DIR__ . '/../../vendor/orchestra/testbench-core/laravel/artisan';
            }

            pcntl_exec(
                'php',
                [
                    $laravel,
                    'queue:work',
                    'request',
                    '--once',
                    '--env=testing',
                    '--timeout=30',
                ]
            );
            exit(1);
        }

        // Parent process: даём воркеру время на инициализацию
        usleep(500_000); // 500ms

        // Отправляем RPC-запрос (getResponse заблокируется до получения ответа или таймаута)
        $response = $externalJob->getResponse(
            message: new ExternalMessage(
                name: 'TASK_CHECK_TARIFF',
                params: ['userId' => 12345]
            ),
            queue: 'integration-test:request'
        );

        // Ждём завершения дочернего процесса
        pcntl_waitpid($pid, $status);

        // Проверяем что получили ответ
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertSame(12345, $response['data']['userId']);
        $this->assertSame('Premium', $response['data']['tariff']);
    }

    /**
     * Fire-and-Forget: sendMessage → consume → выполнение без ответа.
     *
     * Отправляем сообщение, запускаем воркер через exec(), проверяем что задача обработана.
     */
    public function testFireAndForgetCycle(): void
    {
        $this->requireRabbitMQ();

        // Биндим простой job который логирует выполнение в файл
        $logFile = sys_get_temp_dir() . '/laravel-queue-payload-fire-forget-' . uniqid() . '.log';

        $this->app->bind('TASK_SET_FLAG', static function ($app, $params) use ($logFile) {
            return new class ($params, $logFile)
            {
                public function __construct(
                    private readonly array $params,
                    private readonly string $logFile,
                ) {
                }

                public function handle(): void
                {
                    file_put_contents($this->logFile, json_encode($this->params));
                }
            };
        });

        /** @var ExternalJobInterface $externalJob */
        $externalJob = $this->app->make(ExternalJobInterface::class);

        // Отправляем сообщение без ожидания ответа
        $externalJob->sendMessage(
            message: new ExternalMessage(
                name: 'TASK_SET_FLAG',
                params: ['flag' => true, 'test' => 'fire-and-forget']
            ),
            queue: 'integration-test:request'
        );

        // Запускаем воркер для обработки одной задачи
        $laravel = __DIR__ . '/../../vendor/bin/laravel';
        if (!file_exists($laravel)) {
            $laravel = __DIR__ . '/../../vendor/orchestra/testbench-core/laravel/artisan';
        }

        $exitCode = 0;
        $output = [];
        exec(
            sprintf('php %s queue:work request --once --env=testing --timeout=30 2>&1', $laravel),
            $output,
            $exitCode
        );

        // Проверяем что job был выполнен (файл-лог создан)
        $this->assertFileExists($logFile, 'Job должен быть выполнен после обработки очереди');
        $logContent = json_decode(file_get_contents($logFile), true);
        $this->assertSame(['flag' => true, 'test' => 'fire-and-forget'], $logContent);

        // Cleanup
        @unlink($logFile);
    }

    /**
     * Проверка формата payload — кроссплатформенный JSON без PHP-сериализации.
     */
    public function testPayloadFormatIsCrossPlatform(): void
    {
        $this->requireRabbitMQ();

        /** @var ExternalJobInterface $externalJob */
        $externalJob = $this->app->make(ExternalJobInterface::class);

        $externalJob->sendMessage(
            message: new ExternalMessage(
                name: 'TASK_CHECK_TARIFF',
                params: ['userId' => 42]
            ),
            queue: 'integration-test:request'
        );

        // Читаем сообщение напрямую из очереди
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $connection = new AMQPStreamConnection($host, $port, 'guest', 'guest');
        $channel = $connection->channel();

        $queue = 'integration-test:request';

        try {
            $msg = $channel->basic_get($queue, false);
            $this->assertNotNull($msg, 'Сообщение должно быть в очереди');

            $payload = json_decode($msg->body, true, 512, JSON_THROW_ON_ERROR);

            // Проверяем кроссплатформенный формат
            $this->assertArrayHasKey('uuid', $payload);
            $this->assertArrayHasKey('job', $payload);
            $this->assertSame(ExternalHandler::NAME, $payload['job']);
            $this->assertArrayHasKey('data', $payload);
            $this->assertSame('TASK_CHECK_TARIFF', $payload['data']['type']);
            $this->assertSame(['userId' => 42], $payload['data']['params']);

            // ACK сообщение (cleanup)
            $channel->basic_ack($msg->getDeliveryTag());
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /**
     * Проверка что response-очередь получает корректный ответ.
     */
    public function testResponseMessageFormat(): void
    {
        $this->requireRabbitMQ();

        /** @var ExternalJobInterface $externalJob */
        $externalJob = $this->app->make(ExternalJobInterface::class);

        // Отправляем сообщение без correlationId (sendMessage, не getResponse)
        $externalJob->sendMessage(
            message: new ExternalMessage(
                name: 'TASK_CHECK_TARIFF',
                params: ['userId' => 99]
            ),
            queue: 'integration-test:request'
        );

        // Читаем сообщение и проверяем отсутствие response queue
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $connection = new AMQPStreamConnection($host, $port, 'guest', 'guest');
        $channel = $connection->channel();

        $queue = 'integration-test:request';

        try {
            $msg = $channel->basic_get($queue, false);
            $this->assertNotNull($msg, 'Сообщение должно быть в очереди');

            $payload = json_decode($msg->body, true, 512, JSON_THROW_ON_ERROR);

            // При sendMessage (без getResponse) correlationId = null, response queue = null
            $this->assertNull($payload['data']['response']);
            $this->assertNull($payload['id']);

            // ACK сообщение (cleanup)
            $channel->basic_ack($msg->getDeliveryTag());
        } finally {
            $channel->close();
            $connection->close();
        }
    }
}
