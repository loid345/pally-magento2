<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Pally\Payment\Exception\WebhookLockException;
use Pally\Payment\Exception\WebhookOrderNotFoundException;
use Pally\Payment\Model\Order\OrderFinder;
use Pally\Payment\Model\Webhook\Processor;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use Psr\Log\LoggerInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly Processor $processor,
        private readonly OrderFinder $orderFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        $invId = (string) $this->request->getParam('InvId', '');
        $outSum = (string) $this->request->getParam('OutSum', '');
        $signatureValue = (string) $this->request->getParam('SignatureValue', '');
        $custom = (string) $this->request->getParam('custom', '');
        $trsId = (string) $this->request->getParam('TrsId', '');
        $status = (string) $this->request->getParam('Status', '');
        $accountType = (string) $this->request->getParam('AccountType', '');
        $commission = (string) $this->request->getParam('Commission', '');
        $currencyIn = strtoupper((string) $this->request->getParam('CurrencyIn', ''));

        if ($invId === '' || $outSum === '' || $signatureValue === '' || $status === '') {
            $this->logger->warning('Pally webhook: missing required parameters', [
                'has_InvId' => $invId !== '',
                'has_OutSum' => $outSum !== '',
                'has_SignatureValue' => $signatureValue !== '',
                'has_Status' => $status !== '',
            ]);
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'error' => 'bad_request']);
        }

        // Resolve storeId from order to use correct API token for signature verification
        $storeId = $this->resolveStoreId($custom, $invId);

        if (!$this->signatureVerifier->isValid($outSum, $invId, $signatureValue, $storeId)) {
            $this->logger->error('Pally webhook: invalid signature', [
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            return $result->setHttpResponseCode(401)->setData(['ok' => false, 'error' => 'invalid_signature']);
        }

        $webhookData = [
            'InvId' => $invId,
            'OutSum' => $outSum,
            'custom' => $custom,
            'TrsId' => $trsId,
            'Status' => $status,
            'AccountType' => $accountType,
            'Commission' => $commission,
            'CurrencyIn' => $currencyIn,
        ];

        try {
            $this->processor->process($webhookData);
        } catch (WebhookOrderNotFoundException $e) {
            $this->logger->warning('Pally webhook: order not found', [
                'error' => $e->getMessage(),
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            // 404 tells Pally the referenced order will never exist; no point retrying.
            return $result->setHttpResponseCode(404)->setData(['ok' => false, 'error' => 'order_not_found']);
        } catch (WebhookLockException $e) {
            $this->logger->warning('Pally webhook: lock busy, asking Pally to retry', [
                'error' => $e->getMessage(),
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            // 503 signals a transient condition so Pally retries after another worker releases the lock.
            return $result->setHttpResponseCode(503)->setData(['ok' => false, 'error' => 'locked']);
        } catch (\Exception $e) {
            $this->logger->error('Pally webhook: processing error', [
                'error' => $e->getMessage(),
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            // Return 500 so Pally retries the webhook delivery instead of marking it delivered.
            return $result->setHttpResponseCode(500)->setData(['ok' => false, 'error' => 'processing_error']);
        }

        return $result->setHttpResponseCode(200)->setData(['ok' => true]);
    }

    private function resolveStoreId(string $custom, string $invId): ?int
    {
        $order = $this->orderFinder->findByCustomOrInvId($custom, $invId);
        return $order !== null ? (int) $order->getStoreId() : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        unset($request);
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        unset($request);
        return true;
    }
}
