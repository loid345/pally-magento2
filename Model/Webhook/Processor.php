<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Pally\Payment\Model\Order\PaymentStateMachine;
use Psr\Log\LoggerInterface;

class Processor
{
    /**
     * @param OrderCollectionFactory $orderCollectionFactory Order lookup collection factory.
     * @param OrderRepositoryInterface $orderRepository Order repository.
     * @param InvoiceService $invoiceService Invoice service.
     * @param Transaction $transaction DB transaction manager.
     * @param PaymentStateMachine $stateMachine Pally to Magento state mapper.
     * @param LoggerInterface $logger Logger instance.
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $transaction,
        private readonly PaymentStateMachine $stateMachine,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process incoming webhook payload and update order state.
     *
     * @param array $webhookData
     * @return void
     */
    public function process(array $webhookData): void
    {
        $custom = (string) ($webhookData['custom'] ?? '');
        $invId = (string) ($webhookData['InvId'] ?? '');
        $pallyStatus = strtoupper((string) ($webhookData['Status'] ?? ''));
        $trsId = (string) ($webhookData['TrsId'] ?? '');

        $order = $this->findOrder($custom, $invId);
        if ($order === null) {
            $this->logOrderNotFound($custom, $invId);
            return;
        }

        if ($this->isDuplicateFinalNotification($order, $trsId)) {
            $this->logDuplicateNotification($order, $trsId);
            return;
        }

        if ($this->isOrderInFinalState($order)) {
            $this->logOrderAlreadyFinal($order);
            return;
        }

        $this->updatePaymentMetadata($order, $webhookData, $pallyStatus, $trsId);
        $this->applyStatusUpdate($order, $pallyStatus, $trsId);

        $this->logger->info('Pally webhook: processed', [
            'order' => $order->getIncrementId(),
            'status' => $pallyStatus,
            'TrsId' => $trsId,
        ]);
    }

    /**
     * Log order-not-found event for webhook payload.
     *
     * @param string $custom
     * @param string $invId
     * @return void
     */
    private function logOrderNotFound(string $custom, string $invId): void
    {
        $this->logger->warning('Pally webhook: order not found', [
            'custom' => $custom,
            'InvId' => $invId,
        ]);
    }

    /**
     * Check duplicate terminal notification by transaction id.
     *
     * @param Order $order
     * @param string $trsId
     * @return bool
     */
    private function isDuplicateFinalNotification(Order $order, string $trsId): bool
    {
        $payment = $order->getPayment();
        $processedTrsId = $payment->getAdditionalInformation('pally_trs_id');
        $currentPallyStatus = $payment->getAdditionalInformation('pally_status');

        return $processedTrsId === $trsId
            && $this->stateMachine->isFinalStatus((string) $currentPallyStatus);
    }

    /**
     * Log duplicate webhook notification.
     *
     * @param Order $order
     * @param string $trsId
     * @return void
     */
    private function logDuplicateNotification(Order $order, string $trsId): void
    {
        $this->logger->info('Pally webhook: duplicate, skipping', [
            'order' => $order->getIncrementId(),
            'TrsId' => $trsId,
        ]);
    }

    /**
     * Check whether order is already in a terminal Magento state.
     *
     * @param Order $order
     * @return bool
     */
    private function isOrderInFinalState(Order $order): bool
    {
        return in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
    }

    /**
     * Log event when webhook arrives for terminal Magento order state.
     *
     * @param Order $order
     * @return void
     */
    private function logOrderAlreadyFinal(Order $order): void
    {
        $this->logger->info('Pally webhook: order already in final state', [
            'order' => $order->getIncrementId(),
            'state' => $order->getState(),
        ]);
    }

    /**
     * Persist webhook metadata into payment additional information.
     *
     * @param Order $order
     * @param array $webhookData
     * @param string $pallyStatus
     * @param string $trsId
     * @return void
     */
    private function updatePaymentMetadata(Order $order, array $webhookData, string $pallyStatus, string $trsId): void
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation('pally_status', $pallyStatus);

        if ($trsId !== '') {
            $payment->setAdditionalInformation('pally_trs_id', $trsId);
        }

        $metadataMap = [
            'AccountType' => 'pally_account_type',
            'OutSum' => 'pally_out_sum',
            'Commission' => 'pally_commission',
        ];

        foreach ($metadataMap as $payloadKey => $paymentKey) {
            if (!empty($webhookData[$payloadKey])) {
                $payment->setAdditionalInformation($paymentKey, $webhookData[$payloadKey]);
            }
        }
    }

    /**
     * Apply mapped status transition for a Pally status.
     *
     * @param Order $order
     * @param string $pallyStatus
     * @param string $trsId
     * @return void
     */
    private function applyStatusUpdate(Order $order, string $pallyStatus, string $trsId): void
    {
        if (in_array(
            $pallyStatus,
            [PaymentStateMachine::PALLY_STATUS_SUCCESS, PaymentStateMachine::PALLY_STATUS_OVERPAID],
            true
        )) {
            $this->handleSuccess($order, $trsId);
            return;
        }

        if ($pallyStatus === PaymentStateMachine::PALLY_STATUS_FAIL) {
            $this->handleFail($order);
            return;
        }

        if ($pallyStatus === PaymentStateMachine::PALLY_STATUS_UNDERPAID) {
            $this->handleUnderpaid($order);
            return;
        }

        $stateInfo = $this->stateMachine->getMagentoState($pallyStatus);
        $order->setState($stateInfo['state']);
        $order->setStatus($stateInfo['status']);
        $order->addCommentToStatusHistory(
            __('Pally payment status: %1', $pallyStatus)->render()
        );
        $this->orderRepository->save($order);
    }

    /**
     * Handle successful payment status by invoicing and moving order to processing.
     *
     * @param Order $order
     * @param string $trsId
     * @return void
     */
    private function handleSuccess(Order $order, string $trsId): void
    {
        // Don't create invoice if already exists or can't be invoiced
        if ($order->hasInvoices() || !$order->canInvoice()) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus('processing');
            $this->orderRepository->save($order);
            return;
        }

        $payment = $order->getPayment();
        $payment->setTransactionId($trsId);
        $payment->setIsTransactionClosed(true);
        $payment->setIsTransactionPending(false);

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus('processing');
        $order->addCommentToStatusHistory(
            __('Pally payment confirmed. Transaction ID: %1', $trsId)->render()
        );

        $this->transaction->addObject($invoice);
        $this->transaction->addObject($order);
        $this->transaction->save();
    }

    /**
     * Handle underpaid payment status.
     *
     * @param Order $order
     * @return void
     */
    private function handleUnderpaid(Order $order): void
    {
        if ($order->canHold()) {
            $order->hold();
            $order->addCommentToStatusHistory(
                __('Pally: payment underpaid. Order placed on hold for manual review.')->render()
            );
            $this->orderRepository->save($order);
        }
    }

    /**
     * Handle failed payment status.
     *
     * @param Order $order
     * @return void
     */
    private function handleFail(Order $order): void
    {
        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                __('Payment failed via Pally. Order cancelled.')->render()
            );
            $this->orderRepository->save($order);
        }
    }

    /**
     * Find order by increment id or fallback invoice id lookup.
     *
     * @param string $custom
     * @param string $invId
     * @return Order|null
     */
    private function findOrder(string $custom, string $invId): ?Order
    {
        // Primary lookup by custom (order increment_id)
        if ($custom !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $custom);
            $collection->setPageSize(1);
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        // Fallback: search by bill_id in payment additional_information
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
                return $order;
            }
        }

        return null;
    }
}
