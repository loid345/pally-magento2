<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_PREFIX = 'payment/pally/';

    /**
     * @param ScopeConfigInterface $scopeConfig Scope config.
     * @param EncryptorInterface $encryptor Encryptor.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Check whether payment method is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get payment method title.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get payment method description.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDescription(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'description',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get payment instructions.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getInstructions(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'instructions',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get decrypted API token.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiToken(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'api_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === '') {
            return '';
        }

        return (string) $this->encryptor->decrypt($value);
    }

    /**
     * Get merchant shop ID.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getShopId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'shop_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get normalized API base URL without trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'api_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');
    }

    /**
     * Get configured bill type.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getBillType(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'bill_type',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get bill lifetime in minutes.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLifetime(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'lifetime',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if debug logging is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugMode(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'debug',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
