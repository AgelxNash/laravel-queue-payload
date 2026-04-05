<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Feature;

use AgelxNash\LaravelQueuePayload\Tests\Fixtures\FireEventJob;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\TariffUpgradedEvent;
use AgelxNash\LaravelQueuePayload\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use stdClass;

/**
 * Проверяет паттерн "Job-обёртка для триггера Events"
 *
 * Сценарий из README Advanced раздел 1: "Как триггерить Events вместо Jobs?"
 *
 * Отправитель: sendMessage(name: 'TRIGGER_EVENT', params: ['event' => TariffUpgradedEvent::class, 'data' => [...]])
 * Получатель: FireEventJob резолвит event-класс и вызывает event(new TariffUpgradedEvent(...))
 */
class FireEventJobTest extends TestCase
{
    /** FireEventJob::handle() триггерит нужный event */
    public function testFireEventJobDispatchesCorrectEvent(): void
    {
        Event::fake();

        $job = new FireEventJob(TariffUpgradedEvent::class, [12345, 'Premium']);
        $job->handle();

        Event::assertDispatched(TariffUpgradedEvent::class, static function (TariffUpgradedEvent $e): bool {
            return $e->userId === 12345 && $e->tariff === 'Premium';
        });
    }

    /** FireEventJob::handle() не триггерит другие события */
    public function testFireEventJobDoesNotDispatchOtherEvents(): void
    {
        Event::fake();

        $job = new FireEventJob(TariffUpgradedEvent::class, [12345, 'Premium']);
        $job->handle();

        Event::assertNotDispatched(stdClass::class);
    }

    /** Алиас TRIGGER_EVENT через контейнер создаёт корректный FireEventJob */
    public function testContainerAliasTriggerEventCreatesFireEventJob(): void
    {
        Event::fake();

        // Биндим алиас так же, как советует README
        $this->app->bind('TRIGGER_EVENT', static function ($app, array $params): FireEventJob {
            return new FireEventJob($params['event'], $params['data']);
        });

        /** @var FireEventJob $job */
        $job = $this->app->make('TRIGGER_EVENT', [
            'event' => TariffUpgradedEvent::class,
            'data' => [99, 'Basic'],
        ]);

        $job->handle();

        Event::assertDispatched(TariffUpgradedEvent::class, static function (TariffUpgradedEvent $e): bool {
            return $e->userId === 99 && $e->tariff === 'Basic';
        });
    }
}
