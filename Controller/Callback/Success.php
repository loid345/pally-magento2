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
use Magento\Sales\Model\Order;
use Pally\Payment\Model\Order\OrderFinder;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use Psr\Log\LoggerInterface;

/**
 * Success return URL used by Pally after a completed payment.
 *
 * Pally sends the customer's browser here via POST with the following form
 * fields: InvId, OutSum, CurrencyIn, custom, SignatureValue. Because the
 * request is a cross-origin POST from the Pally payment page, we must also
 * opt out of Magento's CSRF validation via CsrfAwareActionInterface.
 *
 * The actual order state transition is driven by the Result URL postback
 * (see Controller/Webhook/Index.php); this controller only redirects the
 * customer to a friendly status page.
 */
class Success implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
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

        // Defence in depth: log a warning when Pally sends a SignatureValue that
        // does not match, but do NOT return early. The success_url is only for
        // redirecting the customer to a friendly page; the authoritative payment
        // confirmation is the Result URL postback (Controller/Webhook/Index.php).
        // Blocking here on a misconfigured api_token would wrongly send paying
        // customers back to the cart.
        if ($signatureValue !== ''
            && !$this->signatureVerifier->isValid($outSum, $invId, $signatureValue)
        ) {
            $this->logger->warning('Pally success redirect: invalid signature (continuing anyway)', [
                'InvId' => $invId,
            ]);
        }

        // Primary: find the order from the Magento checkout session.
        // Fallback: Pally sends `custom` (= order increment_id) and `InvId`
        // in the POST body. When the session is lost after a cross-site POST
        // redirect (SameSite=Lax cookies are not sent on cross-origin POST),
        // we recover the order directly from those params and restore the
        // minimum session state needed by checkout/onepage/success.
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            $custom = (string) $this->request->getParam('custom', '');
            $this->logger->info('Pally success redirect: session order not found, falling back to POST params', [
                'InvId'  => $invId,
                'custom' => $custom,
            ]);
            $order = $this->orderFinder->findByCustomOrInvId($custom, $invId);

            if ($order && $order->getId()) {
                $this->logger->info('Pally success redirect: order recovered from POST params', [
                    'order' => $order->getIncrementId(),
                ]);
                $this->restoreCheckoutSession($order);
            } else {
                $this->logger->warning('Pally success redirect: order not found by POST params', [
                    'InvId'  => $invId,
                    'custom' => $custom,
                ]);
            }
        }

        if (!$order || !$order->getId()) {
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * Restores the minimum checkout session state required by
     * Magento\Checkout\Model\Session\SuccessValidator so that
     * checkout/onepage/success can display the order confirmation page.
     *
     * SuccessValidator::isValid() checks three session keys:
     *   - last_success_quote_id   → set via setLastSuccessQuoteId()
     *   - last_quote_id           → set via setLastQuoteId()
     *   - last_order_id           → set via setLastOrderId()
     *
     * Without all three the success page silently redirects to cart.
     * We also set last_real_order_id (increment ID) which the success
     * page block uses to display the order number to the customer.
     */
    private function restoreCheckoutSession(Order $order): void
    {
        $quoteId = (int) $order->getQuoteId();

        $this->checkoutSession->setLastSuccessQuoteId($quoteId);
        $this->checkoutSession->setLastQuoteId($quoteId);
        $this->checkoutSession->setLastOrderId((int) $order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
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
