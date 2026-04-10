<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Callback;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Pally\Payment\Model\Order\OrderFinder;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use Psr\Log\LoggerInterface;

/**
 * Fail return URL used by Pally after a failed/aborted payment.
 *
 * Pally sends the customer's browser here via POST with the following form
 * fields: InvId, OutSum, CurrencyIn, custom, SignatureValue. Because the
 * request is a cross-origin POST from the Pally payment page, we must also
 * opt out of Magento's CSRF validation via CsrfAwareActionInterface.
 */
class Fail implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly RequestInterface $request,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly OrderFinder $orderFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        $invId = (string) $this->request->getParam('InvId', '');
        $outSum = (string) $this->request->getParam('OutSum', '');
        $signatureValue = (string) $this->request->getParam('SignatureValue', '');

        if (!$this->isSignatureValid($outSum, $invId, $signatureValue)) {
            return $redirect->setPath('checkout/cart');
        }

        $order = $this->resolveOrder($invId);

        if ($order !== null && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            $this->checkoutSession->restoreQuote();
        }

        $this->messageManager->addErrorMessage(
            __('Payment was not completed. Please try again or choose a different payment method.')
        );

        return $redirect->setPath('checkout/cart');
    }

    /**
     * Returns true when the signature is absent (no verification needed) or valid.
     * Logs a warning and returns false when a signature is present but invalid.
     */
    private function isSignatureValid(string $outSum, string $invId, string $signatureValue): bool
    {
        if ($signatureValue === '') {
            return true;
        }

        if (!$this->signatureVerifier->isValid($outSum, $invId, $signatureValue)) {
            $this->logger->warning('Pally fail redirect: invalid signature', [
                'InvId' => $invId,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Resolves the order from the checkout session, falling back to the
     * `custom` / `InvId` POST params when the session is lost after a
     * cross-site POST redirect (SameSite=Lax cookies not sent on cross-origin POST).
     */
    private function resolveOrder(string $invId): ?Order
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if ($order && $order->getId()) {
            return $order;
        }

        $custom = (string) $this->request->getParam('custom', '');
        $order = $this->orderFinder->findByCustomOrInvId($custom, $invId);

        if ($order && $order->getId()) {
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastOrderId($order->getId());
            return $order;
        }

        return null;
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
