<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Pally\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class BillCreate implements ClientInterface
{
    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function placeRequest(TransferInterface $transferObject): array
    {
        $body = $transferObject->getBody();
        $storeId = (int) ($body['__store_id'] ?? 0);
        unset($body['__store_id']);

        $apiUrl = $this->config->getApiUrl($storeId) . '/api/v1/bill/create';
        $apiToken = $this->config->getApiToken($storeId);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiToken,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $this->curl->setTimeout(15);

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally bill/create request', [
                'url' => $apiUrl,
                'body' => $this->maskSensitiveData($body),
            ]);
        }

        $this->curl->post($apiUrl, http_build_query($body));

        $status = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Pally bill/create response', [
                'http_status' => $status,
                'body' => $responseBody,
            ]);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Pally bill/create failed', [
                'http_status' => $status,
                'response' => $responseBody,
            ]);
            throw new \Magento\Payment\Gateway\Http\ClientException(
                __('Payment service temporarily unavailable. Please try again later.')
            );
        }

        $json = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

        return $json;
    }

    private function maskSensitiveData(array $data): array
    {
        $masked = $data;
        foreach (['api_token', 'SignatureValue'] as $key) {
            if (isset($masked[$key])) {
                $masked[$key] = '***';
            }
        }
        return $masked;
    }
}
