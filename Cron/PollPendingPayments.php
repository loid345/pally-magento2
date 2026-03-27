<?php

declare(strict_types=1);

namespace Pally\Payment\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Pally\Payment\Gateway\Http\Client\PaymentStatus;
use Pally\Payment\Model\Ui\ConfigProvider;
use Pally\Payment\Model\Webhook\Processor;
use Psr\Log\LoggerInterface;

class PollPendingPayments
{
    private const PENDING_THRESHOLD_MINUTES = 10;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PaymentStatus $paymentStatusClient,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT);
        $collection->join(
            ['sop' => 'sales_order_payment'],
            'main_table.entity_id = sop.parent_id',
            ['method']
        );
        $collection->addFieldToFilter('sop.method', ConfigProvider::CODE);

        $threshold = new \DateTime();
        $threshold->modify('-' . self::PENDING_THRESHOLD_MINUTES . ' minutes');
        $collection->addFieldToFilter('main_table.created_at', ['lteq' => $threshold->format('Y-m-d H:i:s')]);

        $collection->setPageSize(50);

        foreach ($collection as $order) {
            try {
                $this->pollOrder($order);
            } catch (\Exception $e) {
                $this->logger->error('Pally cron: error polling order', [
                    'order' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function pollOrder(Order $order): void
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }

        $trsId = (string) $payment->getAdditionalInformation('pally_trs_id');
        $billId = (string) $payment->getAdditionalInformation('bill_id');
        $storeId = (int) $order->getStoreId();

        $pallyStatus = null;
        $resolvedTrsId = $trsId;

        // Prefer payment/status if we have a TrsId
        if ($trsId !== '') {
            try {
                $response = $this->paymentStatusClient->getPaymentStatus($trsId, $storeId);
                $pallyStatus = strtoupper((string) ($response['Status'] ?? $response['status'] ?? ''));
            } catch (\Exception $e) {
                $this->logger->debug('Pally cron: payment/status failed, trying bill/status', [
                    'order' => $order->getIncrementId(),
                ]);
            }
        }

        // Fallback to bill/status
        if (!$pallyStatus && $billId !== '') {
            try {
                $response = $this->paymentStatusClient->getBillStatus($billId, $storeId);
                $pallyStatus = strtoupper((string) ($response['Status'] ?? $response['status'] ?? ''));
                if ($resolvedTrsId === '' && !empty($response['TrsId'])) {
                    $resolvedTrsId = (string) $response['TrsId'];
                    $payment->setAdditionalInformation('pally_trs_id', $resolvedTrsId);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Pally cron: bill/status failed', [
                    'order' => $order->getIncrementId(),
                ]);
            }
        }

        if (!$pallyStatus) {
            return;
        }

        $currentStatus = (string) $payment->getAdditionalInformation('pally_status');
        if ($currentStatus === $pallyStatus) {
            return;
        }

        // Build webhook-like data and process
        $webhookData = [
            'InvId' => $billId,
            'OutSum' => sprintf('%.2f', (float) $order->getGrandTotal()),
            'custom' => $order->getIncrementId(),
            'Status' => $pallyStatus,
            'TrsId' => $resolvedTrsId,
        ];

        $this->processor->process($webhookData);

        $this->logger->info('Pally cron: updated order status', [
            'order' => $order->getIncrementId(),
            'status' => $pallyStatus,
        ]);
    }
}
