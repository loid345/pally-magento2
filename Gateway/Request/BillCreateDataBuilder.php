<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Request;

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

    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $storeId = (int) $order->getStoreId();

        $store = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $currency = strtoupper((string) $order->getCurrencyCode());

        // Note: `shop_url` is NOT part of the documented bill/create payload;
        // Pally silently ignores it. The Success/Fail URLs below are enough.
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
        ];

        // Only forward currency_in when it is in Pally's supported set;
        // otherwise skip the field entirely and let Pally fall back to the
        // merchant's default configured currency to avoid a 422.
        if (in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            $request['currency_in'] = $currency;
        }

        return $request;
    }
}
