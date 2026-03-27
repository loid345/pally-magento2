<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Pally\Payment\Gateway\Config\Config;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'pally';

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getConfig(): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'title' => $this->config->getTitle(),
                    'description' => $this->config->getDescription(),
                    'instructions' => $this->config->getInstructions(),
                    'redirectUrl' => $this->urlBuilder->getUrl('pally/redirect/start'),
                ],
            ],
        ];
    }
}
