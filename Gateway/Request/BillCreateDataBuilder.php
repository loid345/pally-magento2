<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Request;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Pally\Payment\Gateway\Config\Config;

class BillCreateDataBuilder implements BuilderInterface
{
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
        $currency = (string) $order->getCurrencyCode();

        // Embed the store id in the free-form `custom` field so that a
        // webhook later delivered by Pally can be routed to the exact
        // (store, increment_id) pair. Magento's default order sequences
        // collide across store groups, so a plain increment_id lookup
        // is not unique in a multi-site install. The signature contract
        // stays unchanged because it only covers OutSum, InvId, and the
        // API token.
        $request = [
            '__store_id' => $storeId,
            'shop_id' => $this->config->getShopId($storeId),
            'order_id' => $order->getOrderIncrementId(),
            'amount' => sprintf('%.2f', (float) $order->getGrandTotalAmount()),
            'type' => $this->config->getBillType($storeId),
            'lifetime' => (string) $this->config->getLifetime($storeId),
            'custom' => $order->getOrderIncrementId() . '|' . $storeId,
            'description' => __('Order #%1', $order->getOrderIncrementId())->render(),
            'payer_pays_commission' => '0',
            'success_url' => $baseUrl . '/pally/callback/success',
            'fail_url' => $baseUrl . '/pally/callback/fail',
            'shop_url' => $baseUrl . '/',
        ];

        if ($currency !== '') {
            $request['currency_in'] = $currency;
        }

        return $request;
    }
}
