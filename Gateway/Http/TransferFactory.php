<?php

declare(strict_types=1);

namespace Pally\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class TransferFactory implements TransferFactoryInterface
{
    /**
     * @param TransferBuilder $transferBuilder Transfer builder.
     */
    public function __construct(
        private readonly TransferBuilder $transferBuilder
    ) {
    }

    /**
     * Create gateway transfer object.
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request): TransferInterface
    {
        return $this->transferBuilder
            ->setBody($request)
            ->build();
    }
}
