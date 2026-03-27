<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

class BillCreateValidator extends AbstractValidator
{
    public function __construct(ResultInterfaceFactory $resultFactory)
    {
        parent::__construct($resultFactory);
    }

    public function validate(array $validationSubject): ResultInterface
    {
        $response = $validationSubject['response'] ?? [];

        $errorMessages = [];
        $errorCodes = [];

        // API returns { success: true/false, bill_id, link_page_url, link_url }
        if (isset($response['success']) && $response['success'] === false) {
            $errorMessages[] = __('Payment service rejected the request.');
            $errorCodes[] = 'API_ERROR';
        }

        if (empty($response['bill_id']) || empty($response['link_page_url'])) {
            $errorMessages[] = __('Invalid response from payment service. Missing bill_id or link_page_url.');
            $errorCodes[] = 'INVALID_RESPONSE';
        }

        $isValid = empty($errorMessages);

        return $this->createResult($isValid, $errorMessages, $errorCodes);
    }
}
