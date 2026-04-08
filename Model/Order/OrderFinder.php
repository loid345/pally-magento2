<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Looks up Magento sales orders by the identifiers Pally reports in its
 * postbacks. Extracted from Processor / webhook controller to keep the
 * webhook Processor class under PHPMD's class-complexity ceiling and to
 * share the Custom → InvId fallback logic in one place.
 */
class OrderFinder
{
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    /**
     * Pally guarantees either `custom` or `InvId` carries the merchant's
     * order increment_id; we try `custom` first and fall back to `InvId`
     * for compatibility with accounts where only one of them is wired up.
     */
    public function findByCustomOrInvId(string $custom, string $invId): ?Order
    {
        if ($custom !== '') {
            $order = $this->findByIncrementId($custom);
            if ($order !== null) {
                return $order;
            }
        }

        if ($invId !== '') {
            return $this->findByIncrementId($invId);
        }

        return null;
    }

    public function findByIncrementId(string $incrementId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();

        if ($order && $order->getId()) {
            return $order;
        }

        return null;
    }
}
