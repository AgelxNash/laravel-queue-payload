<?php

declare(strict_types=1);

return [
    'queue' => [
        // Таймаут ожидания ответа из очереди (в секундах)
        // Используется WorkerOptions::timeout. -1 = бесконечное ожидание (не рекомендуется)
        'timeout' => env('QUEUE_RESPONSE_TIMEOUT', 60),
    ],

    /**
     * HMAC-подпись correlationId для защиты от подделки ответов.
     *
     * Если задан QUEUE_HMAC_SECRET — каждый correlationId подписывается HMAC-SHA256
     * при отправке и верифицируется при получении ответа.
     *
     * По умолчанию ОТКЛЮЧЕНО (пустой секрет).
     *
     * Для включения:
     *   QUEUE_HMAC_SECRET=your-random-secret-key-here
     *
     * Один и тот же секрет должен быть на ВСЕХ микросервисах, участвующих в RPC.
     */
    'hmac' => [
        'secret' => env('QUEUE_HMAC_SECRET', ''),
        'algorithm' => 'sha256',
    ],

    /**
     * Circuit Breaker для RPC-вызовов.
     *
     * После N последовательных таймаутов circuit открывается и RPC-вызовы
     * начинают мгновенно падать с CircuitBreakerOpenException.
     * Через resetTimeout секунд circuit перейдет в half-open и разрешает
     * одну пробную попытку.
     *
     * Установите enabled: false для отключения.
     */
    'circuit_breaker' => [
        'enabled' => (bool) env('QUEUE_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('QUEUE_CIRCUIT_BREAKER_FAILURES', 5),
        'reset_timeout' => (int) env('QUEUE_CIRCUIT_BREAKER_RESET', 30),
    ],

    /**
     * Allowlist job-классов/алиасов, которые могут быть вызваны через ExternalHandler.
     *
     * Если массив пуст — разрешены все алиасы/FQCN (поведение по умолчанию).
     * Если массив не пуст — разрешены ТОЛЬКО ключи из этого массива.
     *
     * Значение может быть:
     *   - null: разрешить алиас как есть (container resolve)
     *   - FQCN string: использовать указанный класс вместо алиаса
     *
     * Пример:
     *   'allowed_jobs' => [
     *       'TASK_CHECK_TARIFF' => \App\Jobs\CheckUserTariffJob::class,
     *       'TASK_SEND_EMAIL'   => null, // разрешить алиас TASK_SEND_EMAIL
     *   ],
     */
    'allowed_jobs' => [],

    /**
     * Режим маршрутизации RPC-ответов.
     *
     * - shared: общая response-очередь сервиса (backward compatible, по умолчанию)
     * - per_request: отдельная временная очередь на каждый RPC-запрос (изоляция)
     * - direct_reply_to: experimental; пока fallback на per_request
     */
    'reply' => [
        'mode' => env('QUEUE_RPC_REPLY_MODE', 'shared'),

        // TTL (секунды) для временных per-request очередей
        'per_request_ttl' => (int) env('QUEUE_RPC_PER_REQUEST_TTL', 60),
    ],
];
