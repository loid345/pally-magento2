<?php

declare(strict_types=1);

namespace Pally\Payment\Cron;

use DateTime;
use Exception;
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

        $threshold = new DateTime();
        $threshold->modify('-' . self::PENDING_THRESHOLD_MINUTES . ' minutes');
        $collection->addFieldToFilter('main_table.created_at', ['lteq' => $threshold->format('Y-m-d H:i:s')]);

        $collection->setPageSize(50);

        foreach ($collection as $order) {
            try {
                $this->pollOrder($order);
            } catch (Exception $e) {
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

        $resolved = $this->resolvePallyStatus($order, $trsId, $billId, $storeId);
        $pallyStatus = $resolved['status'];
        $resolvedTrsId = $resolved['trsId'];

        if ($pallyStatus === '') {
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

    private function resolvePallyStatus(Order $order, string $trsId, string $billId, int $storeId): array
    {
        $resolvedTrsId = $trsId;
        $pallyStatus = $this->fetchPaymentStatus($order, $trsId, $storeId);

        if ($pallyStatus === '' && $billId !== '') {
            $billStatus = $this->fetchBillStatus($order, $billId, $storeId);
            $pallyStatus = $billStatus['status'];
            if ($resolvedTrsId === '' && $billStatus['trsId'] !== '') {
                $resolvedTrsId = $billStatus['trsId'];
                $order->getPayment()?->setAdditionalInformation('pally_trs_id', $resolvedTrsId);
            }
        }

        return [
            'status' => $pallyStatus,
            'trsId' => $resolvedTrsId,
        ];
    }

    private function fetchPaymentStatus(Order $order, string $trsId, int $storeId): string
    {
        if ($trsId === '') {
            return '';
        }

        try {
            $response = $this->paymentStatusClient->getPaymentStatus($trsId, $storeId);
            return strtoupper((string) ($response['Status'] ?? $response['status'] ?? ''));
        } catch (Exception $e) {
            $this->logger->debug('Pally cron: payment/status failed, trying bill/status', [
                'order' => $order->getIncrementId(),
            ]);
            return '';
        }
    }

    private function fetchBillStatus(Order $order, string $billId, int $storeId): array
    {
        try {
            $response = $this->paymentStatusClient->getBillStatus($billId, $storeId);
            return [
                'status' => strtoupper((string) ($response['Status'] ?? $response['status'] ?? '')),
                'trsId' => !empty($response['TrsId']) ? (string) $response['TrsId'] : '',
            ];
        } catch (Exception $e) {
            $this->logger->debug('Pally cron: bill/status failed', [
                'order' => $order->getIncrementId(),
            ]);
            return [
                'status' => '',
                'trsId' => '',
            ];
        }
    }
}
