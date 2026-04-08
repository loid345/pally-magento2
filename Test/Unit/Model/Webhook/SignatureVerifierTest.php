<?php

declare(strict_types=1);

namespace Pally\Payment\Test\Unit\Model\Webhook;

use Pally\Payment\Gateway\Config\Config;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SignatureVerifierTest extends TestCase
{
    private const TOKEN = 'secret-token-123';

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiToken')->willReturn(self::TOKEN);

        $this->verifier = new SignatureVerifier($config, new NullLogger());
    }

    public function testEmptyApiTokenRejected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiToken')->willReturn('');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $verifier = new SignatureVerifier($config, $logger);

        // Even a "legit looking" signature must be rejected when the secret
        // is missing, because md5("OutSum:InvId:") is trivially forgeable.
        self::assertFalse(
            $verifier->isValid('10.00', '100000200', strtoupper(md5('10.00:100000200:')))
        );
    }

    public function testValidSignatureUppercase(): void
    {
        $outSum = '199.99';
        $invId = '100000123';
        $signature = strtoupper(md5($outSum . ':' . $invId . ':' . self::TOKEN));

        self::assertTrue($this->verifier->isValid($outSum, $invId, $signature));
    }

    public function testValidSignatureLowercase(): void
    {
        $outSum = '50.00';
        $invId = '100000124';
        $signature = strtolower(md5($outSum . ':' . $invId . ':' . self::TOKEN));

        self::assertTrue($this->verifier->isValid($outSum, $invId, $signature));
    }

    public function testInvalidSignatureRejected(): void
    {
        self::assertFalse($this->verifier->isValid('100.00', '100000125', 'definitely-not-valid'));
    }

    public function testTamperedAmountRejected(): void
    {
        $outSum = '10.00';
        $invId = '100000126';
        $signature = strtoupper(md5($outSum . ':' . $invId . ':' . self::TOKEN));

        // Attacker bumps the amount but reuses the original signature.
        self::assertFalse($this->verifier->isValid('10000.00', $invId, $signature));
    }

    public function testTamperedInvIdRejected(): void
    {
        $outSum = '10.00';
        $invId = '100000127';
        $signature = strtoupper(md5($outSum . ':' . $invId . ':' . self::TOKEN));

        self::assertFalse($this->verifier->isValid($outSum, '999999999', $signature));
    }

    public function testEmptySignatureRejected(): void
    {
        self::assertFalse($this->verifier->isValid('10.00', '100000128', ''));
    }
}
