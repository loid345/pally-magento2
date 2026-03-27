<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_PREFIX = 'payment/pally/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getDescription(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'description',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getInstructions(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'instructions',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiToken(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'api_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getShopId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'shop_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'api_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');
    }

    public function getBillType(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'bill_type',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getLifetime(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'lifetime',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDebugMode(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'debug',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
