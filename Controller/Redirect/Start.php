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
    /**
     * @param CheckoutSession $checkoutSession Checkout session.
     * @param RedirectFactory $redirectFactory Redirect result factory.
     * @param ManagerInterface $messageManager Message manager.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /**
     * Redirect customer to hosted payment page.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            $this->messageManager->addErrorMessage(__('Order not found.'));
            return $redirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $linkPageUrl = $payment?->getAdditionalInformation('link_page_url');

        if (!$linkPageUrl) {
            $this->messageManager->addErrorMessage(__('Payment link not available. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setUrl($linkPageUrl);
    }
}
