<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Feature;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\ExternalJob;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CheckUserTariffJob;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Проверяет что ExternalHandler корректно находит класс по алиасу из контейнера и вызывает его handle().
 *
 * Сценарий из README раздел 3: "Получение задач и отправка ответа"
 * Связь: TASK_CHECK_TARIFF -> CheckUserTariffJob
 */
class ExternalHandlerDispatchTest extends TestCase
{
    /** ExternalHandler.getCommand() резолвит класс по алиасу из контейнера */
    public function testHandlerResolvesJobByAlias(): void
    {
        // Биндим алиас TASK_CHECK_TARIFF так же, как советует README
        $this->app->bind('TASK_CHECK_TARIFF', CheckUserTariffJob::class);

        $handler = $this->app->make(ExternalHandler::class);

        // Формируем data секцию, которую передаёт ExternalJob в payload
        $data = [
            ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
            ExternalJob::JOB_PARAMS => ['userId' => 12345],
        ];

        // Получаем command через protected-метод (через ReflectionMethod)
        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        $command = $reflection->invoke($handler, $data);

        $this->assertInstanceOf(CheckUserTariffJob::class, $command);
    }

    /** ExternalHandler.getCommand() передаёт параметры в конструктор джобы */
    public function testHandlerPassesParamsToJobConstructor(): void
    {
        $this->app->bind('TASK_CHECK_TARIFF', CheckUserTariffJob::class);

        $handler = $this->app->make(ExternalHandler::class);

        $data = [
            ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
            ExternalJob::JOB_PARAMS => ['userId' => 99999],
        ];

        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        /** @var CheckUserTariffJob $command */
        $command = $reflection->invoke($handler, $data);

        // Проверяем через getExternalPayload что userId попал в конструктор
        $this->assertSame(['userId' => 99999], $command->getExternalPayload());
    }

    /** ExternalHandler.getCommand() без JOB_CLASS делегирует в parent::getCommand() */
    public function testHandlerFallsBackToParentWhenNoJobClass(): void
    {
        $handler = $this->app->make(ExternalHandler::class);

        // Передаём стандартный формат Laravel (без type/JOB_CLASS)
        // нет JOB_CLASS -> наш метод делегирует в parent::getCommand()
        // parent пытается десериализовать 'command' -> получит stdClass
        $data = [
            'commandName' => stdClass::class,
            'command' => serialize(new stdClass()),
        ];

        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($handler, $data);

        // parent::getCommand вернул десериализованный объект
        $this->assertInstanceOf(stdClass::class, $result);
    }

    /** ExternalHandler.fire() вызывает call(), что запускает handle() на джобе */
    public function testHandlerFireCallsHandle(): void
    {
        // Создаём трекинг: была ли вызвана handle
        $handled = false;
        $this->app->bind('TASK_CHECK_TARIFF', static function ($app, $params) use (&$handled) {
            return new class ($handled)
            {
                public function __construct(private bool &$handled)
                {
                }

                public function handle(): void
                {
                    $this->handled = true;
                }
            };
        });

        $handler = $this->app->make(ExternalHandler::class);

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('payload')->willReturn([
            'data' => [
                ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
                ExternalJob::JOB_PARAMS => [],
            ],
        ]);
        $jobMock->method('hasFailed')->willReturn(false);
        $jobMock->method('isDeleted')->willReturn(false);
        $jobMock->method('isReleased')->willReturn(false);
        $jobMock->method('isDeletedOrReleased')->willReturn(false);
        $jobMock->method('uuid')->willReturn('test-uuid');

        $handler->fire($jobMock, [
            ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
            ExternalJob::JOB_PARAMS => [],
        ]);

        $this->assertTrue($handled);
    }

    /** ExternalHandler.getCommand() выбрасывает исключение для job не из allowlist */
    public function testHandlerRejectsJobNotInAllowlist(): void
    {
        // Задаём строгий allowlist
        $this->app['config']->set('agelxnash-queue.allowed_jobs', [
            'TASK_CHECK_TARIFF' => CheckUserTariffJob::class,
        ]);

        $handler = $this->app->make(ExternalHandler::class);

        $data = [
            ExternalJob::JOB_CLASS => 'TASK_UNKNOWN_JOB',
            ExternalJob::JOB_PARAMS => [],
        ];

        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Job 'TASK_UNKNOWN_JOB' is not in the allowed jobs list");

        $reflection->invoke($handler, $data);
    }

    /** ExternalHandler.getCommand() использует FQCN из allowlist вместо алиаса */
    public function testHandlerUsesFqcnFromAllowlist(): void
    {
        $this->app['config']->set('agelxnash-queue.allowed_jobs', [
            'TASK_CHECK_TARIFF' => CheckUserTariffJob::class,
        ]);

        $handler = $this->app->make(ExternalHandler::class);

        $data = [
            ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
            ExternalJob::JOB_PARAMS => ['userId' => 42],
        ];

        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        $command = $reflection->invoke($handler, $data);

        $this->assertInstanceOf(CheckUserTariffJob::class, $command);
    }

    /** ExternalHandler.getCommand() разрешает все job при пустом allowlist */
    public function testHandlerAllowsAllJobsWhenAllowlistIsEmpty(): void
    {
        $this->app['config']->set('agelxnash-queue.allowed_jobs', []);
        $this->app->bind('TASK_CHECK_TARIFF', CheckUserTariffJob::class);

        $handler = $this->app->make(ExternalHandler::class);

        $data = [
            ExternalJob::JOB_CLASS => 'TASK_CHECK_TARIFF',
            ExternalJob::JOB_PARAMS => ['userId' => 777],
        ];

        $reflection = new ReflectionMethod($handler, 'getCommand');
        $reflection->setAccessible(true);

        $command = $reflection->invoke($handler, $data);

        $this->assertInstanceOf(CheckUserTariffJob::class, $command);
    }
}
