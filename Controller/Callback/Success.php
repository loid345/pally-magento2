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
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        // Defence in depth: if Pally POSTs here with a SignatureValue, verify it
        // before trusting session-based order lookup. A mismatch is logged and
        // we still fall through to the cart so the customer sees something sane.
        $invId = (string) $this->request->getParam('InvId', '');
        $outSum = (string) $this->request->getParam('OutSum', '');
        $signatureValue = (string) $this->request->getParam('SignatureValue', '');

        if ($signatureValue !== ''
            && !$this->signatureVerifier->isValid($outSum, $invId, $signatureValue)
        ) {
            $this->logger->warning('Pally success redirect: invalid signature', [
                'InvId' => $invId,
            ]);
            return $redirect->setPath('checkout/cart');
        }

        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setPath('checkout/onepage/success');
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
