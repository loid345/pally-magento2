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
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $transaction,
        private readonly PaymentStateMachine $stateMachine,
        private readonly LoggerInterface $logger
    ) {
    }

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

        $payment = $order->getPayment();
        if ($payment === null) {
            $this->logger->warning('Pally webhook: order has no payment entity', [
                'order' => $order->getIncrementId(),
            ]);
            return;
        }

        if ($this->isDuplicateFinalEvent($order, $payment, $trsId)) {
            return;
        }

        if ($this->isMagentoFinalState($order)) {
            return;
        }

        $this->savePaymentMetadata($payment, $webhookData, $pallyStatus, $trsId);
        $this->applyPallyStatus($order, $pallyStatus, $trsId);

        $this->logger->info('Pally webhook: processed', [
            'order' => $order->getIncrementId(),
            'status' => $pallyStatus,
            'TrsId' => $trsId,
        ]);
    }

    private function logOrderNotFound(string $custom, string $invId): void
    {
        $this->logger->warning('Pally webhook: order not found', [
            'custom' => $custom,
            'InvId' => $invId,
        ]);
    }

    private function isDuplicateFinalEvent(Order $order, \Magento\Sales\Model\Order\Payment $payment, string $trsId): bool
    {
        $processedTrsId = (string) $payment->getAdditionalInformation('pally_trs_id');
        $currentPallyStatus = (string) $payment->getAdditionalInformation('pally_status');
        if ($processedTrsId !== $trsId || !$this->stateMachine->isFinalStatus($currentPallyStatus)) {
            return false;
        }

        $this->logger->info('Pally webhook: duplicate, skipping', [
            'order' => $order->getIncrementId(),
            'TrsId' => $trsId,
        ]);

        return true;
    }

    private function isMagentoFinalState(Order $order): bool
    {
        if (!in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return false;
        }

        $this->logger->info('Pally webhook: order already in final state', [
            'order' => $order->getIncrementId(),
            'state' => $order->getState(),
        ]);

        return true;
    }

    private function savePaymentMetadata(
        \Magento\Sales\Model\Order\Payment $payment,
        array $webhookData,
        string $pallyStatus,
        string $trsId
    ): void {
        $payment->setAdditionalInformation('pally_status', $pallyStatus);

        if ($trsId !== '') {
            $payment->setAdditionalInformation('pally_trs_id', $trsId);
        }

        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_account_type', $webhookData['AccountType'] ?? '');
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_out_sum', $webhookData['OutSum'] ?? '');
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_commission', $webhookData['Commission'] ?? '');
    }

    private function setAdditionalInformationIfNotEmpty(
        \Magento\Sales\Model\Order\Payment $payment,
        string $key,
        mixed $value
    ): void {
        if ($value === '' || $value === null) {
            return;
        }

        $payment->setAdditionalInformation($key, $value);
    }

    private function applyPallyStatus(Order $order, string $pallyStatus, string $trsId): void
    {
        if ($pallyStatus === PaymentStateMachine::PALLY_STATUS_SUCCESS
            || $pallyStatus === PaymentStateMachine::PALLY_STATUS_OVERPAID
        ) {
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
        if ($trsId !== '') {
            $payment->setTransactionId($trsId);
        }
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

    private function findOrder(string $custom, string $invId): ?Order
    {
        // Primary lookup by custom (order increment_id)
        if ($custom !== '') {
            $order = $this->findOrderByIncrementId($custom);
            if ($order !== null) {
                return $order;
            }
        }

        // Fallback: use InvId as order increment_id according to API callback contract.
        if ($invId !== '') {
            $order = $this->findOrderByIncrementId($invId);
            if ($order !== null) {
                return $order;
            }

            $order = $this->findOrderByBillId($invId);
            if ($order !== null) {
                return $order;
            }
        }

        return null;
    }

    private function findOrderByIncrementId(string $incrementId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();

        if ($order && $order->getId()) {
            return $order;
        }

        return null;
    }

    private function findOrderByBillId(string $billId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->join(
            ['sop' => 'sales_order_payment'],
            'main_table.entity_id = sop.parent_id',
            []
        );
        $collection->addFieldToFilter('sop.additional_information', ['like' => '%' . $billId . '%']);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();

        if ($order && $order->getId()) {
            return $order;
        }

        return null;
    }
}
