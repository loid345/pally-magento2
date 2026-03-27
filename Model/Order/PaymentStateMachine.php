<?php

declare(strict_types=1);

namespace Pally\Payment\Model\Order;

use Magento\Sales\Model\Order;

class PaymentStateMachine
{
    public const PALLY_STATUS_NEW = 'NEW';
    public const PALLY_STATUS_PROCESS = 'PROCESS';
    public const PALLY_STATUS_SUCCESS = 'SUCCESS';
    public const PALLY_STATUS_FAIL = 'FAIL';
    public const PALLY_STATUS_UNDERPAID = 'UNDERPAID';
    public const PALLY_STATUS_OVERPAID = 'OVERPAID';

    /**
     * @return array{state: string, status: string}
     */
    public function getMagentoState(string $pallyStatus): array
    {
        return match (strtoupper($pallyStatus)) {
            self::PALLY_STATUS_SUCCESS,
            self::PALLY_STATUS_OVERPAID => [
                'state' => Order::STATE_PROCESSING,
                'status' => 'processing',
            ],
            self::PALLY_STATUS_FAIL => [
                'state' => Order::STATE_CANCELED,
                'status' => 'canceled',
            ],
            self::PALLY_STATUS_UNDERPAID => [
                'state' => Order::STATE_HOLDED,
                'status' => 'holded',
            ],
            default => [
                'state' => Order::STATE_PENDING_PAYMENT,
                'status' => 'pending_payment',
            ],
        };
    }

    public function isFinalStatus(string $pallyStatus): bool
    {
        return in_array(
            strtoupper($pallyStatus),
            [self::PALLY_STATUS_SUCCESS, self::PALLY_STATUS_FAIL, self::PALLY_STATUS_UNDERPAID, self::PALLY_STATUS_OVERPAID],
            true
        );
    }
}
