<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Обработчик задач, который будет использоваться, если в очереди окажутся задачи хандлером ExternalHandler::NAME
 * По умолчанию laravel подсовывает туда Illuminate\Queue\CallQueuedHandler@call, но нам нужно, чтобы
 * имя класса не передавалось в очередь явным образом.
 *
 * Во избежание проблем, все зависимости внутри проекта, необходимые для вызываемой задачи, нужно перечислять
 * в handle методе, а не конструкторе. Поскольку в конструктор пробрасываются параметры перечисленные в payload очереди
 *
 * @TODO: проверить как себя поведет конструктор, если в параметрах будет указан интерфейс объекта
 */
class ExternalHandler extends CallQueuedHandler
{
    /**
     * Задачи, которые будут тригериться внешним сервисом не могут принимать и создавать объекты в конструкторе
     * Соответственно, мы не должны использовать трейт @see \Illuminate\Queue\SerializesModels и т.п.
     */
    public const NAME = 'external';

    /**
     * По умолчанию тригерится метод @fire, но т.к. мы просто подменяем дефолтный обработчик (чтобы не писать кучу кода)
     * без явного указания хандлер метода, а по умолчанию этот метод CallQueuedHandler@call, то просто проксируем вызов
     *
     * @param array<string, mixed> $data
     */
    public function fire(Job $job, array $data): void
    {
        $this->call($job, $data);
    }

    /**
     * Подменяем метод инициализации класса джобы, на случай если получили имя джобы и ее параметры,
     * а не сериализованный объект
     *
     * @return object|string
     *
     * @throws RuntimeException Если job-класс не найден в allowlist
     *
     * @inheritDoc
     */
    /** @phpstan-ignore missingType.iterableValue */
    protected function getCommand(array $data)
    {
        $jobName = Arr::get($data, ExternalJob::JOB_CLASS);
        if ($jobName === null || $jobName === '') {
            return parent::getCommand($data);
        }

        // Security: проверяем allowlist
        $allowedJobs = config('agelxnash-queue.allowed_jobs', []);

        if ($allowedJobs !== []) {
            // Если allowlist задан — разрешаем только алиасы из него
            if (!array_key_exists($jobName, $allowedJobs)) {
                throw new RuntimeException(
                    "Job '{$jobName}' is not in the allowed jobs list. "
                    . "Add it to config('agelxnash-queue.allowed_jobs') or clear the allowlist to allow all."
                );
            }

            // Если в allowlist указан FQCN — используем его вместо алиаса
            $target = $allowedJobs[$jobName];
            if (is_string($target) && class_exists($target)) {
                $params = DtoSerializer::decodeParams(Arr::get($data, ExternalJob::JOB_PARAMS, []));

                return $this->container->make($target, $params);
            }
        }

        // Fallback: разрешаем через container (алиас или FQCN)
        $params = DtoSerializer::decodeParams(Arr::get($data, ExternalJob::JOB_PARAMS, []));

        return $this->container->make($jobName, $params);
    }
}
