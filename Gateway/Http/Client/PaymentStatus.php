<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Http\Client;

use JsonException;
use Magento\Framework\HTTP\Client\Curl;
use Pally\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PaymentStatus
{
    /**
     * @param Curl $curl HTTP client.
     * @param Config $config Module configuration.
     * @param LoggerInterface $logger Logger instance.
     */
    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Request payment status by transaction id.
     *
     * @param string $paymentId
     * @param int|null $storeId
     * @return array
     */
    public function getPaymentStatus(string $paymentId, ?int $storeId = null): array
    {
        $apiUrl = $this->config->getApiUrl($storeId)
            . '/api/v1/payment/status?id=' . rawurlencode($paymentId);
        $apiToken = $this->config->getApiToken($storeId);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiToken,
        ]);
        $this->curl->setTimeout(10);

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally payment/status request', ['url' => $apiUrl]);
        }

        $this->curl->get($apiUrl);

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally payment/status response', [
                'http_status' => $status,
                'body' => $body,
            ]);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Pally payment/status failed', [
                'http_status' => $status,
                'response' => $body,
            ]);
            throw new RuntimeException('Pally payment/status failed with HTTP ' . $status);
        }

        return $this->decodeJson($body, 'payment/status');
    }

    /**
     * Request bill status by bill id.
     *
     * @param string $billId
     * @param int|null $storeId
     * @return array
     */
    public function getBillStatus(string $billId, ?int $storeId = null): array
    {
        $apiUrl = $this->config->getApiUrl($storeId)
            . '/api/v1/bill/status?id=' . rawurlencode($billId);
        $apiToken = $this->config->getApiToken($storeId);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiToken,
        ]);
        $this->curl->setTimeout(10);

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally bill/status request', ['url' => $apiUrl]);
        }

        $this->curl->get($apiUrl);

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally bill/status response', [
                'http_status' => $status,
                'body' => $body,
            ]);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Pally bill/status failed', [
                'http_status' => $status,
                'response' => $body,
            ]);
            throw new RuntimeException('Pally bill/status failed with HTTP ' . $status);
        }

        return $this->decodeJson($body, 'bill/status');
    }

    /**
     * Decode JSON response and throw a runtime exception on failure.
     *
     * @param string $body
     * @param string $endpoint
     * @return array
     */
    private function decodeJson(string $body, string $endpoint): array
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Pally ' . $endpoint . ': invalid JSON response', [
                'response' => $body,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Pally ' . $endpoint . ' returned invalid JSON');
        }
    }
}
