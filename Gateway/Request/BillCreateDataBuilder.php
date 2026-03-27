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
     * @param Config $config Module configuration.
     * @param StoreManagerInterface $storeManager Store manager.
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Build request payload for bill creation.
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $storeId = (int) $order->getStoreId();

        $store = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        return [
            '__store_id' => $storeId,
            'shop_id' => $this->config->getShopId($storeId),
            'order_id' => $order->getOrderIncrementId(),
            'amount' => sprintf('%.2f', (float) $order->getGrandTotalAmount()),
            'type' => $this->config->getBillType($storeId),
            'lifetime' => (string) $this->config->getLifetime($storeId),
            'custom' => $order->getOrderIncrementId(),
            'description' => __('Order #%1', $order->getOrderIncrementId())->render(),
            'payer_pays_commission' => '0',
            'success_url' => $baseUrl . '/pally/return/success',
            'fail_url' => $baseUrl . '/pally/return/fail',
            'shop_url' => $baseUrl . '/',
        ];
    }
}
