<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Pally\Payment\Gateway\Config\Config;

class SignatureVerifier
{
    /**
     * @param Config $config Module configuration.
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Validate webhook signature against configured API token.
     *
     * @param string $outSum Payment amount.
     * @param string $invId Bill identifier.
     * @param string $signatureValue Received signature.
     * @param int|null $storeId Store scope.
     * @return bool
     */
    public function isValid(string $outSum, string $invId, string $signatureValue, ?int $storeId = null): bool
    {
        $apiToken = $this->config->getApiToken($storeId);
        $expected = strtoupper(hash('md5', $outSum . ':' . $invId . ':' . $apiToken));

        return hash_equals($expected, strtoupper($signatureValue));
    }
}
