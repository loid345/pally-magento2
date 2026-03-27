<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Callback;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

class Success implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setPath('checkout/onepage/success');
    }
}
