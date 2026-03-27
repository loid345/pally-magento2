<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Pally\Payment\Gateway\Config\Config;

class SignatureVerifier
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function isValid(string $outSum, string $invId, string $signatureValue, ?int $storeId = null): bool
    {
        $apiToken = $this->config->getApiToken($storeId);
        $expected = strtoupper(md5($outSum . ':' . $invId . ':' . $apiToken));

        return hash_equals($expected, strtoupper($signatureValue));
    }
}
