<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

class BillCreateHandler implements HandlerInterface
{
    /**
     * Persist bill creation data on payment instance.
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        $payment->setAdditionalInformation('bill_id', $response['bill_id'] ?? '');
        $payment->setAdditionalInformation('link_page_url', $response['link_page_url'] ?? '');

        if (!empty($response['link_url'])) {
            $payment->setAdditionalInformation('link_url', $response['link_url']);
        }

        $payment->setAdditionalInformation('pally_status', 'NEW');
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);
    }
}
