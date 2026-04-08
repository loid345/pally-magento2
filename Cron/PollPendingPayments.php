<?php

declare(strict_types=1);

namespace Pally\Payment\Cron;

use DateTime;
use DateTimeZone;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Pally\Payment\Gateway\Http\Client\PaymentStatus;
use Pally\Payment\Model\Ui\ConfigProvider;
use Pally\Payment\Model\Webhook\Processor;
use Psr\Log\LoggerInterface;

class PollPendingPayments
{
    private const PENDING_THRESHOLD_MINUTES = 10;
    private const PAGE_SIZE = 50;
    private const MAX_ORDERS_PER_RUN = 500;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PaymentStatus $paymentStatusClient,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $threshold = new DateTime('now', new DateTimeZone('UTC'));
        $threshold->modify('-' . self::PENDING_THRESHOLD_MINUTES . ' minutes');
        $thresholdString = $threshold->format('Y-m-d H:i:s');

        $processed = 0;
        $seenIds = [];

        do {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT);
            $collection->join(
                ['sop' => 'sales_order_payment'],
                'main_table.entity_id = sop.parent_id',
                ['method']
            );
            $collection->addFieldToFilter('sop.method', ConfigProvider::CODE);
            $collection->addFieldToFilter('main_table.created_at', ['lteq' => $thresholdString]);
            if ($seenIds !== []) {
                $collection->addFieldToFilter('main_table.entity_id', ['nin' => $seenIds]);
            }
            $collection->setOrder('main_table.created_at', 'ASC');
            $collection->setPageSize(self::PAGE_SIZE);
            $collection->setCurPage(1);

            $itemsOnPage = 0;
            foreach ($collection as $order) {
                $itemsOnPage++;
                $processed++;
                $seenIds[] = (int) $order->getId();
                try {
                    $this->pollOrder($order);
                } catch (\Exception $e) {
                    $this->logger->error('Pally cron: error polling order', [
                        'order' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($processed >= self::MAX_ORDERS_PER_RUN) {
                    return;
                }
            }
        } while ($itemsOnPage === self::PAGE_SIZE);
    }

    private function pollOrder(Order $order): void
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }

        $statusData = $this->resolveStatusData($order, $payment);
        $pallyStatus = $statusData['status'];
        $resolvedTrsId = $statusData['trs_id'];
        $reportedAmount = $statusData['amount'];

        if (!$pallyStatus) {
            return;
        }

        $currentStatus = (string) $payment->getAdditionalInformation('pally_status');
        if ($currentStatus === $pallyStatus) {
            return;
        }

        // Forward the REAL amount reported by Pally to the processor so that
        // the SUCCESS amount-matching check in Model/Webhook/Processor.php is
        // meaningful; when Pally did not surface an amount we fall back to the
        // grand total to preserve backward behaviour for SUCCESS statuses.
        $outSum = $reportedAmount !== ''
            ? $reportedAmount
            : sprintf('%.2f', (float) $order->getGrandTotal());

        $webhookData = [
            'InvId' => $order->getIncrementId(),
            'OutSum' => $outSum,
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

    /**
     * @return array{status: string, trs_id: string, amount: string}
     */
    private function resolveStatusData(Order $order, Payment $payment): array
    {
        $trsId = (string) $payment->getAdditionalInformation('pally_trs_id');
        $billId = (string) $payment->getAdditionalInformation('bill_id');
        $storeId = (int) $order->getStoreId();

        $paymentStatus = $this->fetchPaymentStatus($order, $trsId, $storeId);
        if ($paymentStatus['status'] !== '') {
            return [
                'status' => $paymentStatus['status'],
                'trs_id' => $trsId,
                'amount' => $paymentStatus['amount'],
            ];
        }

        return $this->fetchBillStatus($order, $billId, $trsId, $storeId);
    }

    /**
     * @return array{status: string, amount: string}
     */
    private function fetchPaymentStatus(Order $order, string $trsId, int $storeId): array
    {
        if ($trsId === '') {
            return ['status' => '', 'amount' => ''];
        }

        try {
            $response = $this->paymentStatusClient->getPaymentStatus($trsId, $storeId);
        } catch (\Exception $e) {
            $this->logger->debug('Pally cron: payment/status failed, trying bill/status', [
                'order' => $order->getIncrementId(),
            ]);
            return ['status' => '', 'amount' => ''];
        }

        return [
            'status' => strtoupper((string) ($response['status'] ?? '')),
            'amount' => $this->formatAmount($response['amount'] ?? null),
        ];
    }

    /**
     * @return array{status: string, trs_id: string, amount: string}
     */
    private function fetchBillStatus(
        Order $order,
        string $billId,
        string $trsId,
        int $storeId
    ): array {
        if ($billId === '') {
            return ['status' => '', 'trs_id' => $trsId, 'amount' => ''];
        }

        try {
            $response = $this->paymentStatusClient->getBillStatus($billId, $storeId);
        } catch (\Exception $e) {
            $this->logger->debug('Pally cron: bill/status failed', [
                'order' => $order->getIncrementId(),
            ]);
            return ['status' => '', 'trs_id' => $trsId, 'amount' => ''];
        }

        return [
            'status' => strtoupper((string) ($response['status'] ?? '')),
            'trs_id' => $trsId,
            'amount' => $this->formatAmount($response['amount'] ?? null),
        ];
    }

    private function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return sprintf('%.2f', (float) $value);
    }
}
