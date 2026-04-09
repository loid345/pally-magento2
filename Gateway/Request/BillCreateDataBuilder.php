<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Pally\Payment\Gateway\Config\Config;

class BillCreateDataBuilder implements BuilderInterface
{
    /**
     * Pally only accepts these three currencies in currency_in; any other
     * value returns HTTP 422 from POST /api/v1/bill/create.
     *
     * @see docs/pally-api.md
     */
    private const SUPPORTED_CURRENCIES = ['RUB', 'USD', 'EUR'];

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws LocalizedException when the order currency is not one of the
     *         three currencies Pally supports. The Magento checkout already
     *         filters Pally out for unsupported currencies via the
     *         CurrencyValidator wired into PallyPaymentValidatorPool, so this
     *         is a defence-in-depth check for off-checkout flows (admin
     *         orders, REST API, etc.) that bypass canUseForCurrency.
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $storeId = (int) $order->getStoreId();

        $store = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $currency = strtoupper((string) $order->getCurrencyCode());

        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new LocalizedException(
                __('Pally accepts payments in RUB, USD or EUR only. Order currency: %1.', $currency)
            );
        }

        // Note: `shop_url` is NOT part of the documented bill/create payload;
        // Pally silently ignores it. The Success/Fail/Return URLs below are
        // enough. `return_url` powers the "Back to shop" button on the Pally
        // payment form — its host must match the merchant's domain.
        $request = [
            '__store_id' => $storeId,
            'shop_id' => $this->config->getShopId($storeId),
            'order_id' => $order->getOrderIncrementId(),
            'amount' => sprintf('%.2f', (float) $order->getGrandTotalAmount()),
            'type' => $this->config->getBillType($storeId),
            'ttl' => (string) $this->config->getLifetime($storeId),
            'custom' => $order->getOrderIncrementId(),
            'description' => __('Order #%1', $order->getOrderIncrementId())->render(),
            'payer_pays_commission' => '0',
            'success_url' => $baseUrl . '/pally/callback/success',
            'fail_url' => $baseUrl . '/pally/callback/fail',
            'return_url' => $baseUrl . '/checkout/cart',
            'currency_in' => $currency,
        ];

        return $request;
    }
}
