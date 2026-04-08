<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Http\Client;

use JsonException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Pally\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PaymentStatus
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getPaymentStatus(string $paymentId, ?int $storeId = null): array
    {
        $apiUrl = $this->config->getApiUrl($storeId)
            . '/api/v1/payment/status?id=' . rawurlencode($paymentId);

        return $this->getJson($apiUrl, $storeId, 'payment/status');
    }

    public function getBillStatus(string $billId, ?int $storeId = null): array
    {
        $apiUrl = $this->config->getApiUrl($storeId)
            . '/api/v1/bill/status?id=' . rawurlencode($billId);

        return $this->getJson($apiUrl, $storeId, 'bill/status');
    }

    private function getJson(string $apiUrl, ?int $storeId, string $endpoint): array
    {
        $apiToken = $this->config->getApiToken($storeId);

        // Fresh Curl instance per call to avoid carrying over headers /
        // response state between concurrent requests.
        $curl = $this->curlFactory->create();
        $curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiToken,
        ]);
        $curl->setTimeout(10);

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally ' . $endpoint . ' request', ['url' => $apiUrl]);
        }

        $curl->get($apiUrl);

        $status = $curl->getStatus();
        $body = $curl->getBody();

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally ' . $endpoint . ' response', [
                'http_status' => $status,
                'body' => $body,
            ]);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Pally ' . $endpoint . ' failed', [
                'http_status' => $status,
                'response' => $body,
            ]);
            throw new RuntimeException('Pally ' . $endpoint . ' failed with HTTP ' . $status);
        }

        return $this->decodeJson($body, $endpoint);
    }

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
