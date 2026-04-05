<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Contracts\Queue;

use Illuminate\Contracts\Queue\Job;

/**
 * Интерфейс для обработчика ответов из очереди с целью подготовить их к ожидаемому нами формату
 */
interface ResponsePrepareInterface
{
    public function __invoke(Job $job): mixed;
}
