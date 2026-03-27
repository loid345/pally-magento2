<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Return;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;

class Fail implements HttpGetActionInterface
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
     * Handle failure return URL from payment provider.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        if ($order && $order->getId() && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            $this->checkoutSession->restoreQuote();
        }

        $this->messageManager->addErrorMessage(
            __('Payment was not completed. Please try again or choose a different payment method.')
        );

        return $redirect->setPath('checkout/cart');
    }
}
