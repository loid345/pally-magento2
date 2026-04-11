<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Webhook;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Pally\Payment\Exception\WebhookLockException;
use Pally\Payment\Exception\WebhookOrderNotFoundException;
use Pally\Payment\Model\Order\OrderFinder;
use Pally\Payment\Model\Order\PaymentStateMachine;
use Psr\Log\LoggerInterface;

class Processor
{
    private const LOCK_PREFIX = 'pally_webhook_order_';
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly OrderFinder $orderFinder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly TransactionBuilderInterface $transactionBuilder,
        private readonly PaymentStateMachine $stateMachine,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(array $webhookData): void
    {
        $custom = (string) ($webhookData['custom'] ?? '');
        $invId = (string) ($webhookData['InvId'] ?? '');

        $order = $this->orderFinder->findByCustomOrInvId($custom, $invId);
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
            $this->processLocked($order, $webhookData);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function processLocked(Order $order, array $webhookData): void
    {
        $pallyStatus = strtoupper((string) ($webhookData['Status'] ?? ''));
        $trsId = (string) ($webhookData['TrsId'] ?? '');

        $payment = $order->getPayment();
        if ($payment === null) {
            $this->logger->warning('Pally webhook: order has no payment entity', [
                'order' => $order->getIncrementId(),
            ]);
            return;
        }

        if ($this->isDuplicateFinalEvent($order, $payment, $pallyStatus)) {
            return;
        }

        if ($this->isMagentoFinalState($order)) {
            return;
        }

        if ($this->rejectIfMismatch($order, $webhookData, $pallyStatus)) {
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

    /**
     * Reject the webhook with a status-history note if SUCCESS was reported but
     * the amount or currency does not match the order. Returns true when the
     * update was rejected (and the order was saved with the explanatory note),
     * false otherwise.
     */
    private function rejectIfMismatch(Order $order, array $webhookData, string $pallyStatus): bool
    {
        if ($pallyStatus !== PaymentStateMachine::PALLY_STATUS_SUCCESS) {
            return false;
        }

        $outSum = (string) ($webhookData['OutSum'] ?? '');
        if ($outSum !== '' && !$this->isAmountMatching($order, $outSum)) {
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
            return true;
        }

        // The amount check above is only meaningful if the currency matches.
        // Pally guarantees CurrencyIn in every postback, so we can compare it
        // against the order currency and reject the update if they disagree.
        $currencyIn = strtoupper((string) ($webhookData['CurrencyIn'] ?? ''));
        if ($currencyIn !== '' && !$this->isCurrencyMatching($order, $currencyIn)) {
            $this->logger->error('Pally webhook: SUCCESS currency mismatch', [
                'order' => $order->getIncrementId(),
                'expected' => $order->getOrderCurrencyCode(),
                'received' => $currencyIn,
            ]);
            $order->addCommentToStatusHistory(
                __(
                    'Pally webhook rejected: currency mismatch (expected %1, received %2).',
                    (string) $order->getOrderCurrencyCode(),
                    $currencyIn
                )->render()
            );
            $this->orderRepository->save($order);
            return true;
        }

        return false;
    }

    private function logOrderNotFound(string $custom, string $invId): void
    {
        $this->logger->warning('Pally webhook: order not found', [
            'custom' => $custom,
            'InvId' => $invId,
        ]);
    }

    private function isDuplicateFinalEvent(Order $order, Payment $payment, string $newStatus): bool
    {
        $currentPallyStatus = (string) $payment->getAdditionalInformation('pally_status');
        if (!$this->stateMachine->isFinalStatus($currentPallyStatus)) {
            return false;
        }

        // Allow late-arriving positive outcomes to overwrite a prior negative
        // final status (FAIL / UNDERPAID). This happens when Pally initially
        // reports FAIL and later retries with SUCCESS/OVERPAID, or when a
        // shopper underpays and then tops the bill up.
        $negativeFinals = [
            PaymentStateMachine::PALLY_STATUS_FAIL,
            PaymentStateMachine::PALLY_STATUS_UNDERPAID,
        ];
        $positiveFinals = [
            PaymentStateMachine::PALLY_STATUS_SUCCESS,
            PaymentStateMachine::PALLY_STATUS_OVERPAID,
        ];
        if (in_array($currentPallyStatus, $negativeFinals, true)
            && in_array($newStatus, $positiveFinals, true)
        ) {
            $this->logger->info('Pally webhook: upgrading negative final status to positive', [
                'order'          => $order->getIncrementId(),
                'current_status' => $currentPallyStatus,
                'new_status'     => $newStatus,
            ]);
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
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_trs_id', $trsId);
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_account_type', $webhookData['AccountType'] ?? '');
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_out_sum', $webhookData['OutSum'] ?? '');
        $this->setAdditionalInformationIfNotEmpty($payment, 'pally_commission', $webhookData['Commission'] ?? '');
    }

    private function setAdditionalInformationIfNotEmpty(
        Payment $payment,
        string $key,
        mixed $value
    ): void {
        if (in_array($value, ['', null], true)) {
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
        // Note: empty string is not in [NEW, PROCESS] so no separate '' check needed.
        if (!in_array(
            $pallyStatus,
            [PaymentStateMachine::PALLY_STATUS_NEW, PaymentStateMachine::PALLY_STATUS_PROCESS],
            true
        )) {
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
        $captureTransaction = null;
        $payment = $order->getPayment();
        if ($payment !== null) {
            if ($trsId !== '') {
                $payment->setTransactionId($trsId);
                $captureTransaction = $this->transactionBuilder
                    ->setPayment($payment)
                    ->setOrder($order)
                    ->setTransactionId($trsId)
                    ->setFailSafe(true)
                    ->build(PaymentTransaction::TYPE_CAPTURE);
                $captureTransaction->setIsClosed(true);
            }
            $payment->setIsTransactionClosed(true);
            $payment->setIsTransactionPending(false);
        }

        // Late SUCCESS arriving after a FAIL/UNDERPAID that put the order on
        // hold needs to lift the hold first; Magento refuses to leave HOLDED
        // via setState() alone. unhold() resets back to the previous state
        // (typically pending_payment), then the SUCCESS flow promotes as usual.
        // canUnhold() already checks getState() === STATE_HOLDED internally.
        if ($order->canUnhold()) {
            $order->unhold();
        }

        // STATE_PAYMENT_REVIEW blocks canInvoice(). Orders may land there if
        // BillCreateHandler previously set setIsTransactionPending(true). Transition
        // out of that state before the canInvoice() check so existing orders
        // placed before the BillCreateHandler fix can still be invoiced.
        if ($order->getState() === Order::STATE_PAYMENT_REVIEW) {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus('pending_payment');
        }

        // Don't create invoice if already exists or can't be invoiced;
        // still persist the transaction flags set above.
        if ($order->hasInvoices() || !$order->canInvoice()) {
            $this->applyPostPaymentState($order);
            $order->addCommentToStatusHistory(
                __('Pally payment confirmed. Transaction ID: %1', $trsId)->render()
            );
            $dbTxn = $this->transactionFactory->create();
            $dbTxn->addObject($order);
            if ($captureTransaction !== null) {
                $dbTxn->addObject($captureTransaction);
            }
            $dbTxn->save();
            return;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        $this->applyPostPaymentState($order);
        $order->addCommentToStatusHistory(
            __('Pally payment confirmed. Transaction ID: %1', $trsId)->render()
        );

        // Save invoice + order + capture transaction atomically using a fresh
        // Transaction per call; sharing a single injected Transaction instance
        // across webhook calls would accumulate stale objects.
        $dbTxn = $this->transactionFactory->create();
        $dbTxn->addObject($invoice);
        $dbTxn->addObject($order);
        if ($captureTransaction !== null) {
            $dbTxn->addObject($captureTransaction);
        }
        $dbTxn->save();
    }

    /**
     * Sets the post-payment order state/status.
     *
     * For orders that require physical shipment (canShip() = true) the state
     * is set to processing — the merchant still needs to ship the goods.
     * For fully virtual/downloadable orders (canShip() = false) there is
     * nothing to ship, so the order goes straight to complete, which is
     * consistent with how Magento's native payment methods behave and allows
     * fulfilment observers (e.g. digital-key delivery) to fire immediately.
     */
    private function applyPostPaymentState(Order $order): void
    {
        if ($order->canShip()) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus('processing');
        } else {
            $order->setState(Order::STATE_COMPLETE);
            $order->setStatus('complete');
        }
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
        // Pally retries postbacks up to 5 times (10^n strategy) and a real
        // payment can land AFTER an initial FAIL — see docs/pally-api.md
        // "Postback / Retry". If we hard-cancel the order on the first FAIL,
        // a later SUCCESS cannot recover it (Magento has no native
        // un-cancel: stock has been returned, gift cards refunded, etc.).
        // We therefore put the order on hold instead, mirroring UNDERPAID
        // handling. The merchant can cancel manually, or Magento's built-in
        // sales_clean_orders cron will auto-cancel stale pending orders.
        if ($order->canHold()) {
            $order->hold();
            $order->addCommentToStatusHistory(
                __('Pally: payment failed. Order placed on hold pending late retries.')->render()
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
        // Use bccomp to avoid IEEE-754 rounding error on amounts with fractional
        // parts (e.g. 199.99 vs. 199.989999 after repeated float arithmetic).
        // We compare at two decimal places, mirroring bill/create's %.2f formatting.
        $expected = sprintf('%.2f', (float) $order->getGrandTotal());
        $received = sprintf('%.2f', (float) $outSum);

        return bccomp($expected, $received, 2) === 0;
    }

    private function isCurrencyMatching(Order $order, string $currencyIn): bool
    {
        $orderCurrency = strtoupper((string) $order->getOrderCurrencyCode());
        if ($orderCurrency === '') {
            // Some degenerate test orders have no currency set; don't block them.
            return true;
        }

        return $orderCurrency === $currencyIn;
    }

}
