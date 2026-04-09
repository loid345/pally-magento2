<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * Currency validator wired into the Magento payment Adapter via the
 * "currency" key of PallyPaymentValidatorPool. Hides the Pally payment
 * method from checkout when the quote currency is not one of the three
 * currencies Pally accepts in `currency_in` (RUB / USD / EUR), per
 * docs/pally-api.md.
 *
 * Magento's \Magento\Payment\Model\Method\Adapter::canUseForCurrency()
 * looks up this validator and calls validate(['currency' => <code>,
 * 'storeId' => <id>]).
 */
class CurrencyValidator extends AbstractValidator
{
    /**
     * Pally only accepts these three currencies; bill/create returns
     * HTTP 422 for anything else (see docs/pally-api.md).
     */
    private const SUPPORTED_CURRENCIES = ['RUB', 'USD', 'EUR'];

    public function validate(array $validationSubject): ResultInterface
    {
        $currency = strtoupper((string) ($validationSubject['currency'] ?? ''));

        if (in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return $this->createResult(true);
        }

        return $this->createResult(
            false,
            [__('Pally accepts payments in RUB, USD or EUR only.')],
            ['CURRENCY_NOT_SUPPORTED']
        );
    }
}
