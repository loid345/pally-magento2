<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BillType implements OptionSourceInterface
{
    /**
     * Return payment bill type options.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'normal', 'label' => __('Normal')],
            ['value' => 'multi', 'label' => __('Multi')],
        ];
    }
}
