<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\ExternalHandler;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessage;
use AgelxNash\LaravelQueuePayload\Queue\ExternalMessageBuilder;
use PHPUnit\Framework\TestCase;

class ExternalMessageBuilderTest extends TestCase
{
    public function testBuilderCreatesMessageWithDefaults(): void
    {
        $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')->build();

        $this->assertSame('TASK_CHECK_TARIFF', $message->getName());
        $this->assertSame([], $message->getParams());
        $this->assertSame(ExternalHandler::NAME, $message->getHandler());
    }

    public function testBuilderSetsParams(): void
    {
        $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')
            ->params(['userId' => 12345])
            ->build();

        $this->assertSame(['userId' => 12345], $message->getParams());
    }

    public function testBuilderAddsSingleParam(): void
    {
        $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')
            ->param('userId', 12345)
            ->param('region', 'eu')
            ->build();

        $this->assertSame(['userId' => 12345, 'region' => 'eu'], $message->getParams());
    }

    public function testBuilderSetsCustomHandler(): void
    {
        $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')
            ->handler('go-billing-handler')
            ->build();

        $this->assertSame('go-billing-handler', $message->getHandler());
    }

    public function testBuilderIsImmutable(): void
    {
        $builder = ExternalMessageBuilder::make('TASK_CHECK_TARIFF');

        $builder1 = $builder->params(['a' => 1]);
        $builder2 = $builder->params(['b' => 2]);

        $this->assertNotSame($builder1, $builder2);
        $this->assertSame(['a' => 1], $builder1->build()->getParams());
        $this->assertSame(['b' => 2], $builder2->build()->getParams());

        // Оригинальный builder не изменён
        $this->assertSame([], $builder->build()->getParams());
    }

    public function testBuilderChaining(): void
    {
        $message = ExternalMessageBuilder::make('TASK_SEND_EMAIL')
            ->params(['to' => 'user@example.com'])
            ->param('subject', 'Hello')
            ->handler('notification-handler')
            ->build();

        $this->assertSame('TASK_SEND_EMAIL', $message->getName());
        $this->assertSame(['to' => 'user@example.com', 'subject' => 'Hello'], $message->getParams());
        $this->assertSame('notification-handler', $message->getHandler());
    }

    public function testExternalMessageStaticMake(): void
    {
        $message = ExternalMessage::make('TASK_CHECK_TARIFF')
            ->params(['userId' => 42])
            ->build();

        $this->assertInstanceOf(ExternalMessage::class, $message);
        $this->assertSame('TASK_CHECK_TARIFF', $message->getName());
        $this->assertSame(['userId' => 42], $message->getParams());
    }

    public function testToMessageReturnsMessageInterface(): void
    {
        $message = ExternalMessageBuilder::make('TASK_CHECK_TARIFF')
            ->params(['userId' => 1])
            ->toMessage();

        $this->assertInstanceOf(ExternalMessage::class, $message);
    }
}
