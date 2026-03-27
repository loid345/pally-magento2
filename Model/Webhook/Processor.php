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
        $custom = $webhookData['custom'] ?? '';
        $invId = $webhookData['InvId'] ?? '';
        $pallyStatus = strtoupper((string) ($webhookData['Status'] ?? ''));
        $trsId = (string) ($webhookData['TrsId'] ?? '');

        $order = $this->findOrder($custom, $invId);
        if (!$order) {
            $this->logger->warning('Pally webhook: order not found', [
                'custom' => $custom,
                'InvId' => $invId,
            ]);
            return;
        }

        // Idempotency: skip if already processed with this TrsId
        $payment = $order->getPayment();
        $processedTrsId = $payment->getAdditionalInformation('pally_trs_id');
        $currentPallyStatus = $payment->getAdditionalInformation('pally_status');

        if ($processedTrsId === $trsId && $this->stateMachine->isFinalStatus((string) $currentPallyStatus)) {
            $this->logger->info('Pally webhook: duplicate, skipping', [
                'order' => $order->getIncrementId(),
                'TrsId' => $trsId,
            ]);
            return;
        }

        // Don't update if order is already in a final state
        if (in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            $this->logger->info('Pally webhook: order already in final state', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
            ]);
            return;
        }

        // Save payment info
        $payment->setAdditionalInformation('pally_status', $pallyStatus);
        if ($trsId) {
            $payment->setAdditionalInformation('pally_trs_id', $trsId);
        }
        if (!empty($webhookData['AccountType'])) {
            $payment->setAdditionalInformation('pally_account_type', $webhookData['AccountType']);
        }
        if (!empty($webhookData['OutSum'])) {
            $payment->setAdditionalInformation('pally_out_sum', $webhookData['OutSum']);
        }
        if (!empty($webhookData['Commission'])) {
            $payment->setAdditionalInformation('pally_commission', $webhookData['Commission']);
        }

        $stateInfo = $this->stateMachine->getMagentoState($pallyStatus);

        if ($pallyStatus === PaymentStateMachine::PALLY_STATUS_SUCCESS
            || $pallyStatus === PaymentStateMachine::PALLY_STATUS_OVERPAID
        ) {
            $this->handleSuccess($order, $trsId);
        } elseif ($pallyStatus === PaymentStateMachine::PALLY_STATUS_FAIL) {
            $this->handleFail($order);
        } elseif ($pallyStatus === PaymentStateMachine::PALLY_STATUS_UNDERPAID) {
            $this->handleUnderpaid($order);
        } else {
            $order->setState($stateInfo['state']);
            $order->setStatus($stateInfo['status']);
            $order->addCommentToStatusHistory(
                __('Pally payment status: %1', $pallyStatus)->render()
            );
            $this->orderRepository->save($order);
        }

        $this->logger->info('Pally webhook: processed', [
            'order' => $order->getIncrementId(),
            'status' => $pallyStatus,
            'TrsId' => $trsId,
        ]);
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
