<?php

declare(strict_types=1);

namespace Pally\Payment\Test\Unit\Model\Webhook;

use Pally\Payment\Model\Webhook\Processor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure static {@see Processor::parseCustom()}
 * helper. The method is deliberately static and collaborator-free
 * precisely so it can be validated here without pulling in the rest
 * of the Magento framework.
 */
class ProcessorParseCustomTest extends TestCase
{
    #[DataProvider('customProvider')]
    public function testParseCustom(string $custom, string $expectedIncrement, ?int $expectedStoreId): void
    {
        $parsed = Processor::parseCustom($custom);

        self::assertSame($expectedIncrement, $parsed['increment_id']);
        self::assertSame($expectedStoreId, $parsed['store_id']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?int}>
     */
    public static function customProvider(): array
    {
        return [
            'empty custom yields empty increment and null store'
                => ['', '', null],
            'plain increment id (legacy format) yields null store'
                => ['000000123', '000000123', null],
            'composite format with single-digit store id'
                => ['000000123|1', '000000123', 1],
            'composite format with multi-digit store id'
                => ['000000123|42', '000000123', 42],
            'composite format with alphanumeric increment id prefix'
                => ['ORD-000000123|5', 'ORD-000000123', 5],
            'composite format with store id zero is accepted as admin store'
                => ['000000123|0', '000000123', 0],
            'trailing pipe with empty store id falls back to legacy lookup'
                => ['000000123|', '000000123|', null],
            'non-numeric store id falls back to legacy lookup'
                => ['000000123|abc', '000000123|abc', null],
            'increment id that itself contains a pipe uses the last delimiter'
                => ['weird|id|7', 'weird|id', 7],
        ];
    }
}
