<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Return;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

class Success implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession Checkout session.
     * @param RedirectFactory $redirectFactory Redirect result factory.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * Handle success return URL from payment provider.
     *
     * @return ResultInterface
     */
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
