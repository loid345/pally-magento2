<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Pally\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class SignatureVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isValid(string $outSum, string $invId, string $signatureValue, ?int $storeId = null): bool
    {
        $apiToken = $this->config->getApiToken($storeId);
        if ($apiToken === '') {
            // Fail-safe: an empty api_token would make md5("OutSum:InvId:") a
            // trivially forgeable signature, so treat any verification attempt
            // as invalid. The admin must configure a real token.
            $this->logger->error('Pally signature check: api_token is not configured, rejecting request');
            return false;
        }

        $expected = strtoupper(hash('md5', $outSum . ':' . $invId . ':' . $apiToken));

        return hash_equals($expected, strtoupper($signatureValue));
    }
}
