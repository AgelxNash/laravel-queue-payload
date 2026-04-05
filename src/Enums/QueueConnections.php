<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Enums;

enum QueueConnections: string
{
    case SYNC = 'sync';
    case REQUEST = 'request';
    case RESPONSE = 'response';
}
