<?php

declare(strict_types=1);

namespace AgelxNash\LaravelQueuePayload\Tests\Unit\Queue;

use AgelxNash\LaravelQueuePayload\Queue\HmacSigner;
use PHPUnit\Framework\TestCase;

class HmacSignerTest extends TestCase
{
    public function testNoOpWhenSecretIsEmpty(): void
    {
        $signer = new HmacSigner('');

        $this->assertFalse($signer->isEnabled());
        $this->assertSame('abc-123', $signer->sign('abc-123'));
        $this->assertSame('abc-123', $signer->verify('abc-123'));
    }

    public function testSignAndVerifyWithSecret(): void
    {
        $signer = new HmacSigner('my-secret');

        $this->assertTrue($signer->isEnabled());

        $signed = $signer->sign('abc-123');
        $this->assertStringContainsString('abc-123.', $signed);

        $verified = $signer->verify($signed);
        $this->assertSame('abc-123', $verified);
    }

    public function testVerifyFailsWithWrongSecret(): void
    {
        $signer1 = new HmacSigner('secret-a');
        $signer2 = new HmacSigner('secret-b');

        $signed = $signer1->sign('abc-123');
        $verified = $signer2->verify($signed);

        $this->assertFalse($verified);
    }

    public function testVerifyFailsWithTamperedSignature(): void
    {
        $signer = new HmacSigner('my-secret');

        $signed = $signer->sign('abc-123');
        $tampered = $signed . 'x'; // ломаем подпись

        $verified = $signer->verify($tampered);
        $this->assertFalse($verified);
    }

    public function testVerifyFailsWithMissingSignature(): void
    {
        $signer = new HmacSigner('my-secret');

        // Нет точки — нет подписи
        $verified = $signer->verify('abc-123');
        $this->assertFalse($verified);
    }

    public function testDifferentAlgorithms(): void
    {
        $signerSha256 = new HmacSigner('secret', 'sha256');
        $signerSha512 = new HmacSigner('secret', 'sha512');

        $signed256 = $signerSha256->sign('abc');
        $signed512 = $signerSha512->sign('abc');

        // Подписи разные из-за разных алгоритмов
        $this->assertNotSame($signed256, $signed512);

        // Каждый верифицирует свою
        $this->assertSame('abc', $signerSha256->verify($signed256));
        $this->assertSame('abc', $signerSha512->verify($signed512));

        // Кросс-верификация падает
        $this->assertFalse($signerSha256->verify($signed512));
        $this->assertFalse($signerSha512->verify($signed256));
    }

    public function testSignProducesDeterministicOutput(): void
    {
        $signer = new HmacSigner('my-secret');

        $signed1 = $signer->sign('abc-123');
        $signed2 = $signer->sign('abc-123');

        $this->assertSame($signed1, $signed2);
    }
}
