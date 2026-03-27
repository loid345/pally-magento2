<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BillType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'normal', 'label' => __('Normal')],
            ['value' => 'multi', 'label' => __('Multi')],
        ];
    }
}
