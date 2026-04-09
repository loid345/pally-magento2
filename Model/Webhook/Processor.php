<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Magento\Framework\DB\Transaction;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Pally\Payment\Exception\WebhookLockException;
use Pally\Payment\Exception\WebhookOrderNotFoundException;
use Pally\Payment\Model\Order\PaymentStateMachine;
use Psr\Log\LoggerInterface;

class Processor
{
    private const LOCK_PREFIX = 'pally_webhook_order_';
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $transaction,
        private readonly PaymentStateMachine $stateMachine,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(array $webhookData): void
    {
        $custom = (string) ($webhookData['custom'] ?? '');
        $invId = (string) ($webhookData['InvId'] ?? '');
        $pallyStatus = strtoupper((string) ($webhookData['Status'] ?? ''));
        $trsId = (string) ($webhookData['TrsId'] ?? '');
        $outSum = (string) ($webhookData['OutSum'] ?? '');

        $parsed = self::parseCustom($custom);
        $order = $this->findOrder($parsed['increment_id'], $parsed['store_id'], $invId);
        if ($order === null) {
            $this->logOrderNotFound($custom, $invId);
            throw new WebhookOrderNotFoundException(
                sprintf('Pally webhook: order not found (custom=%s, InvId=%s)', $custom, $invId)
            );
        }

        $lockName = self::LOCK_PREFIX . $order->getIncrementId();
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            $this->logger->warning('Pally webhook: could not acquire lock', [
                'order' => $order->getIncrementId(),
            ]);
            throw new WebhookLockException(
                sprintf('Pally webhook: lock busy for order %s', $order->getIncrementId())
            );
        }

        try {
            // Reload the order under the lock to get the freshest state.
            $order = $this->orderRepository->get((int) $order->getId());

            $payment = $order->getPayment();
            if ($payment === null) {
                $this->logger->warning('Pally webhook: order has no payment entity', [
                    'order' => $order->getIncrementId(),
                ]);
                return;
            }

            if ($this->isDuplicateFinalEvent($order, $payment)) {
                return;
            }

            if ($this->isMagentoFinalState($order)) {
                return;
            }

            // For positive payment outcomes, the reported amount must match the order total.
            // UNDERPAID/OVERPAID legitimately differ and are handled separately.
            if ($outSum !== ''
                && $pallyStatus === PaymentStateMachine::PALLY_STATUS_SUCCESS
                && !$this->isAmountMatching($order, $outSum)
            ) {
                $this->logger->error('Pally webhook: SUCCESS amount mismatch', [
                    'order' => $order->getIncrementId(),
                    'expected' => $order->getGrandTotal(),
                    'received' => $outSum,
                ]);
                $order->addCommentToStatusHistory(
                    __(
                        'Pally webhook rejected: amount mismatch (expected %1, received %2).',
                        (string) $order->getGrandTotal(),
                        $outSum
                    )->render()
                );
                $this->orderRepository->save($order);
                return;
            }

            $this->savePaymentMetadata($payment, $webhookData, $pallyStatus, $trsId);
            $this->applyPallyStatus($order, $pallyStatus, $trsId);

            $this->logger->info('Pally webhook: processed', [
                'order' => $order->getIncrementId(),
                'status' => $pallyStatus,
                'TrsId' => $trsId,
            ]);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function logOrderNotFound(string $custom, string $invId): void
    {
        $this->logger->warning('Pally webhook: order not found', [
            'custom' => $custom,
            'InvId' => $invId,
        ]);
    }

    private function isDuplicateFinalEvent(Order $order, Payment $payment): bool
    {
        $currentPallyStatus = (string) $payment->getAdditionalInformation('pally_status');
        if (!$this->stateMachine->isFinalStatus($currentPallyStatus)) {
            return false;
        }

        $this->logger->info('Pally webhook: already in final Pally status, skipping', [
            'order'          => $order->getIncrementId(),
            'current_status' => $currentPallyStatus,
        ]);

        return true;
    }

    private function isMagentoFinalState(Order $order): bool
    {
        if (!in_array(
            $order->getState(),
            [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED],
            true
        )) {
            return false;
        }

        $this->logger->info('Pally webhook: order already in final state', [
            'order' => $order->getIncrementId(),
            'state' => $order->getState(),
        ]);

        return true;
    }

    private function savePaymentMetadata(
        Payment $payment,
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
        Payment $payment,
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

        // Empty or unknown status: persist metadata only, do not touch order state.
        if ($pallyStatus === ''
            || !in_array(
                $pallyStatus,
                [PaymentStateMachine::PALLY_STATUS_NEW, PaymentStateMachine::PALLY_STATUS_PROCESS],
                true
            )
        ) {
            $this->orderRepository->save($order);
            return;
        }

        // Known intermediate Pally statuses (NEW/PROCESS) — keep order in pending_payment with a note.
        $stateInfo = $this->stateMachine->getMagentoState($pallyStatus);
        if ($order->getState() !== $stateInfo['state']) {
            $order->setState($stateInfo['state']);
            $order->setStatus($stateInfo['status']);
        }
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
        } else {
            $order->addCommentToStatusHistory(
                __('Pally: payment underpaid.')->render()
            );
        }
        $this->orderRepository->save($order);
    }

    private function handleFail(Order $order): void
    {
        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                __('Payment failed via Pally. Order cancelled.')->render()
            );
        } else {
            $order->addCommentToStatusHistory(
                __('Payment failed via Pally.')->render()
            );
        }
        $this->orderRepository->save($order);
    }

    private function isAmountMatching(Order $order, string $outSum): bool
    {
        $expected = (float) $order->getGrandTotal();
        $received = (float) $outSum;

        return abs($expected - $received) < 0.01;
    }

    private function findOrder(string $incrementId, ?int $storeId, string $invId): ?Order
    {
        // Primary lookup: increment_id scoped by store_id parsed from `custom`.
        // This is the only path that is unique in a multi-site install.
        if ($incrementId !== '') {
            $order = $this->findOrderByIncrementId($incrementId, $storeId);
            if ($order !== null) {
                return $order;
            }
        }

        // Legacy fallback for orders created before the store_id was
        // embedded in `custom`: global lookup by increment_id.
        if ($incrementId !== '' && $storeId !== null) {
            $order = $this->findOrderByIncrementId($incrementId, null);
            if ($order !== null) {
                return $order;
            }
        }

        // Last-resort fallback: use InvId as the increment_id. Pally
        // echoes InvId verbatim from our bill_create request, so it
        // mirrors the order increment_id on fresh deliveries.
        if ($invId !== '') {
            $order = $this->findOrderByIncrementId($invId, null);
            if ($order !== null) {
                return $order;
            }
        }

        return null;
    }

    private function findOrderByIncrementId(string $incrementId, ?int $storeId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId);
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', $storeId);
        }
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();

        if ($order && $order->getId()) {
            return $order;
        }

        return null;
    }

    /**
     * Split the `custom` field that Pally echoes back in a webhook into
     * its composite parts. The field is produced by
     * {@see \Pally\Payment\Gateway\Request\BillCreateDataBuilder} as
     * `{increment_id}|{store_id}`. Orders that predate that format are
     * handled transparently by returning a null store id.
     *
     * @return array{increment_id: string, store_id: ?int}
     */
    public static function parseCustom(string $custom): array
    {
        if ($custom === '') {
            return ['increment_id' => '', 'store_id' => null];
        }

        $pipePos = strrpos($custom, '|');
        if ($pipePos === false) {
            return ['increment_id' => $custom, 'store_id' => null];
        }

        $incrementId = substr($custom, 0, $pipePos);
        $storeIdRaw = substr($custom, $pipePos + 1);

        if ($storeIdRaw === '' || !ctype_digit($storeIdRaw)) {
            return ['increment_id' => $custom, 'store_id' => null];
        }

        return [
            'increment_id' => $incrementId,
            'store_id' => (int) $storeIdRaw,
        ];
    }
}
