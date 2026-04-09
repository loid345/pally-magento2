<?php

declare(strict_types=1);

namespace Pally\Payment\Test\Unit\Model\Order;

use Magento\Sales\Model\Order;
use Pally\Payment\Model\Order\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    private PaymentStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new PaymentStateMachine();
    }

    #[DataProvider('statusToStateProvider')]
    public function testGetMagentoState(string $pallyStatus, string $expectedState, string $expectedStatus): void
    {
        $result = $this->machine->getMagentoState($pallyStatus);

        self::assertSame($expectedState, $result['state']);
        self::assertSame($expectedStatus, $result['status']);
    }

    public static function statusToStateProvider(): array
    {
        return [
            'success'   => ['SUCCESS',   Order::STATE_PROCESSING,      'processing'],
            'overpaid'  => ['OVERPAID',  Order::STATE_PROCESSING,      'processing'],
            // FAIL is mapped to HOLDED (not CANCELED) so a late SUCCESS retry
            // from Pally can still recover the order — see PaymentStateMachine.
            'fail'      => ['FAIL',      Order::STATE_HOLDED,          'holded'],
            'underpaid' => ['UNDERPAID', Order::STATE_HOLDED,          'holded'],
            'new'       => ['NEW',       Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            'process'   => ['PROCESS',   Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            'unknown'   => ['WHATEVER',  Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            'lowercase' => ['success',   Order::STATE_PROCESSING,      'processing'],
        ];
    }

    #[DataProvider('finalStatusProvider')]
    public function testIsFinalStatus(string $pallyStatus, bool $expected): void
    {
        self::assertSame($expected, $this->machine->isFinalStatus($pallyStatus));
    }

    public static function finalStatusProvider(): array
    {
        return [
            ['SUCCESS',   true],
            ['OVERPAID',  true],
            ['UNDERPAID', true],
            ['FAIL',      true],
            ['success',   true],
            ['NEW',       false],
            ['PROCESS',   false],
            ['',          false],
            ['WHATEVER',  false],
        ];
    }
}
