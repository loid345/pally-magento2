<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Pally\Payment\Gateway\Config\Config;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'pally';

    /**
     * @param Config $config Module configuration.
     * @param UrlInterface $urlBuilder URL builder.
     * @param StoreManagerInterface $storeManager Store manager.
     */
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Provide checkout JS config for the payment method.
     *
     * @return array
     */
    public function getConfig(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isActive($storeId)) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'title' => $this->config->getTitle($storeId),
                    'description' => $this->config->getDescription($storeId),
                    'instructions' => $this->config->getInstructions($storeId),
                    'redirectUrl' => $this->urlBuilder->getUrl('pally/redirect/start'),
                ],
            ],
        ];
    }
}
