<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Contracts\Queue\DtoInterface;
use AgelxNash\LaravelQueuePayload\Queue\DtoSerializer;
use AgelxNash\LaravelQueuePayload\Tests\Fixtures\CheckTariffDto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DtoSerializerTest extends TestCase
{
    public function testSerializeDto(): void
    {
        $dto = new class (userId: 42, region: 'eu') implements DtoInterface
        {
            public function __construct(
                public readonly int $userId,
                public readonly ?string $region = null,
            ) {
            }
        };

        $data = DtoSerializer::serialize($dto);

        $this->assertSame(['userId' => 42, 'region' => 'eu'], $data);
    }

    public function testDeserializeDto(): void
    {
        $dto = DtoSerializer::deserialize(CheckTariffDto::class, [
            'userId' => 42,
            'region' => 'eu',
        ]);

        $this->assertInstanceOf(CheckTariffDto::class, $dto);
        $this->assertSame(42, $dto->userId);
        $this->assertSame('eu', $dto->region);
    }

    public function testEncodeParamsWithDto(): void
    {
        $dto = new CheckTariffDto(userId: 42, region: 'eu');

        $encoded = DtoSerializer::encodeParams(['tariff' => $dto, 'extra' => 'value']);

        $this->assertArrayHasKey('tariff', $encoded);
        $this->assertArrayHasKey('__dto_class', $encoded['tariff']);
        $this->assertArrayHasKey('__dto_data', $encoded['tariff']);
        $this->assertSame(CheckTariffDto::class, $encoded['tariff']['__dto_class']);
        $this->assertSame(['userId' => 42, 'region' => 'eu'], $encoded['tariff']['__dto_data']);
        $this->assertSame('value', $encoded['extra']);
    }

    public function testDecodeParamsWithDto(): void
    {
        $params = [
            'tariff' => [
                '__dto_class' => CheckTariffDto::class,
                '__dto_data' => ['userId' => 42, 'region' => 'eu'],
            ],
            'extra' => 'value',
        ];

        $decoded = DtoSerializer::decodeParams($params);

        $this->assertInstanceOf(CheckTariffDto::class, $decoded['tariff']);
        $this->assertSame(42, $decoded['tariff']->userId);
        $this->assertSame('value', $decoded['extra']);
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $dto = new CheckTariffDto(userId: 99, region: 'us');
        $params = ['tariff' => $dto, 'flag' => true];

        $encoded = DtoSerializer::encodeParams($params);
        $decoded = DtoSerializer::decodeParams($encoded);

        $this->assertInstanceOf(CheckTariffDto::class, $decoded['tariff']);
        $this->assertSame(99, $decoded['tariff']->userId);
        $this->assertSame('us', $decoded['tariff']->region);
        $this->assertTrue($decoded['flag']);
    }

    public function testDeserializeMissingRequiredParam(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing required DTO parameter 'userId'");

        DtoSerializer::deserialize(CheckTariffDto::class, ['region' => 'eu']);
    }

    public function testDeserializeNonExistentClass(): void
    {
        $this->expectException(RuntimeException::class);

        DtoSerializer::deserialize('NonExistentDto', []);
    }

    public function testIsDto(): void
    {
        $dto = new CheckTariffDto(userId: 1);

        $this->assertTrue(DtoSerializer::isDto($dto));
        $this->assertFalse(DtoSerializer::isDto(['userId' => 1]));
        $this->assertFalse(DtoSerializer::isDto('string'));
    }
}
