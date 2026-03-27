<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Pally\Payment\Model\Webhook\Processor;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use Psr\Log\LoggerInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param RequestInterface $request HTTP request instance.
     * @param JsonFactory $resultJsonFactory JSON result factory.
     * @param SignatureVerifier $signatureVerifier Webhook signature verifier.
     * @param Processor $processor Webhook processor.
     * @param OrderCollectionFactory $orderCollectionFactory Order collection factory.
     * @param LoggerInterface $logger Logger instance.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly Processor $processor,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle webhook callback request.
     *
     * @return ResultInterface
     */
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

        if ($invId === '' || $outSum === '' || $signatureValue === '') {
            $this->logger->warning('Pally webhook: missing required parameters');
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
        ];

        try {
            $this->processor->process($webhookData);
        } catch (\Exception $e) {
            $this->logger->error('Pally webhook: processing error', [
                'error' => $e->getMessage(),
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            return $result->setHttpResponseCode(200)->setData(['ok' => true]);
        }

        return $result->setHttpResponseCode(200)->setData(['ok' => true]);
    }

    /**
     * Resolve store scope for signature validation.
     *
     * @param string $custom Increment ID from webhook.
     * @param string $invId External invoice ID.
     * @return int|null
     */
    private function resolveStoreId(string $custom, string $invId): ?int
    {
        if ($custom !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $custom);
            $collection->setPageSize(1);
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return (int) $order->getStoreId();
            }
        }

        if ($invId !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->join(
                ['sop' => 'sales_order_payment'],
                'main_table.entity_id = sop.parent_id',
                []
            );
            $collection->addFieldToFilter('sop.additional_information', ['like' => '%' . $invId . '%']);
            $collection->setPageSize(1);
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return (int) $order->getStoreId();
            }
        }

        return null;
    }

    /**
     * @param RequestInterface $_request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $_request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $_request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $_request): ?bool
    {
        return true;
    }
}
