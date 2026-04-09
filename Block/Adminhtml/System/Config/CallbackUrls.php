<?php

declare(strict_types=1);

namespace Pally\Payment\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only admin config field that shows the full set of callback
 * URLs the merchant must paste into the Pally cabinet ("Ссылки"
 * section). The URLs are built from the base URL of the store that
 * matches the current admin scope switcher (store view → website
 * default store → default store view) so multi-site installations
 * see the correct host without manual editing.
 */
class CallbackUrls extends Field
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $baseUrl = rtrim((string) $this->resolveStore()->getBaseUrl(), '/');

        $urls = [
            (string) __('Success URL')       => $baseUrl . '/pally/callback/success',
            (string) __('Fail URL')          => $baseUrl . '/pally/callback/fail',
            (string) __('Result URL (webhook)') => $baseUrl . '/pally/webhook',
            (string) __('Refund URL')        => $baseUrl . '/pally/webhook',
            (string) __('Chargeback URL')    => $baseUrl . '/pally/webhook',
        ];

        $rows = '';
        foreach ($urls as $label => $url) {
            $rows .= sprintf(
                '<tr>'
                . '<td style="padding:4px 8px;font-weight:600;white-space:nowrap;">%s</td>'
                . '<td style="padding:4px 8px;">'
                . '<input type="text" readonly="readonly" value="%s" '
                . 'style="width:100%%;font-family:monospace;" '
                . 'onclick="this.select();" />'
                . '</td>'
                . '</tr>',
                $this->escapeHtml($label),
                $this->escapeHtmlAttr($url)
            );
        }

        $intro = $this->escapeHtml(
            (string) __('Copy these URLs into your Pally cabinet under "Ссылки":')
        );

        return '<div class="pally-callback-urls">'
            . '<p style="margin:0 0 8px 0;">' . $intro . '</p>'
            . '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
            . '</div>';
    }

    /**
     * Resolve the store that the admin is currently configuring so the
     * displayed base URL matches the scope switcher at the top of the
     * config page. Falls back to the default store view when no scope
     * is selected (System scope).
     */
    private function resolveStore(): StoreInterface
    {
        $request = $this->getRequest();
        $storeCode = (string) $request->getParam('store');
        if ($storeCode !== '') {
            return $this->storeManager->getStore($storeCode);
        }

        $websiteCode = (string) $request->getParam('website');
        if ($websiteCode !== '') {
            return $this->storeManager->getWebsite($websiteCode)->getDefaultStore();
        }

        return $this->storeManager->getDefaultStoreView();
    }
}
