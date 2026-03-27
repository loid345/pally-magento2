<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class TransferFactory implements TransferFactoryInterface
{
    public function __construct(
        private readonly TransferBuilder $transferBuilder
    ) {
    }

    public function create(array $request): TransferInterface
    {
        return $this->transferBuilder
            ->setBody($request)
            ->build();
    }
}
