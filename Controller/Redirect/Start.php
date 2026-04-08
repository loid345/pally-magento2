<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;

class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            $this->messageManager->addErrorMessage(__('Order not found.'));
            return $redirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $linkPageUrl = (string) ($payment?->getAdditionalInformation('link_page_url') ?? '');

        if ($linkPageUrl === '' || !$this->isSafePaymentUrl($linkPageUrl)) {
            $this->messageManager->addErrorMessage(__('Payment link not available. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setUrl($linkPageUrl);
    }

    /**
     * Defence-in-depth check for the Pally-issued payment page URL before
     * redirecting the customer. The value originates from a signed API
     * response, but we still require an absolute https:// URL with a host
     * to prevent any stored-URL tampering turning this controller into an
     * open redirect.
     */
    private function isSafePaymentUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return $scheme === 'https' && $host !== '';
    }
}
