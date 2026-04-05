<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests;

use AgelxNash\LaravelQueuePayload\ServiceProvider;
use Orchestra\Testbench\TestCase as LaravelTestCase;

abstract class TestCase extends LaravelTestCase
{
    public const SERVICE_NAME = 'phpunit';

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('agelxnash-queue.service_name', self::SERVICE_NAME);
    }
}
