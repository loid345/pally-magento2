<?php

declare(strict_types=1);

namespace Pally\Payment\Test\Unit\Gateway\Validator;

use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Pally\Payment\Gateway\Validator\CurrencyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyValidatorTest extends TestCase
{
    private CurrencyValidator $validator;

    protected function setUp(): void
    {
        if (!class_exists(ResultInterfaceFactory::class)) {
            self::markTestSkipped('Magento payment framework is not available in this environment.');
        }

        $factory = $this->getMockBuilder(ResultInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $factory->method('create')->willReturnCallback(
            static function (array $args): Result {
                return new Result(
                    (bool) ($args['isValid'] ?? false),
                    (array) ($args['failsDescription'] ?? []),
                    (array) ($args['errorCodes'] ?? [])
                );
            }
        );

        $this->validator = new CurrencyValidator($factory);
    }

    #[DataProvider('supportedCurrencyProvider')]
    public function testSupportedCurrenciesAccepted(string $currency): void
    {
        $result = $this->validator->validate(['currency' => $currency, 'storeId' => 1]);

        self::assertTrue($result->isValid());
    }

    public static function supportedCurrencyProvider(): array
    {
        return [
            'rub'           => ['RUB'],
            'usd'           => ['USD'],
            'eur'           => ['EUR'],
            'rub-lowercase' => ['rub'],
            'eur-mixed'     => ['Eur'],
        ];
    }

    #[DataProvider('unsupportedCurrencyProvider')]
    public function testUnsupportedCurrenciesRejected(string $currency): void
    {
        $result = $this->validator->validate(['currency' => $currency, 'storeId' => 1]);

        self::assertFalse($result->isValid());
        self::assertContains('CURRENCY_NOT_SUPPORTED', $result->getErrorCodes());
    }

    public static function unsupportedCurrencyProvider(): array
    {
        return [
            'jpy' => ['JPY'],
            'gbp' => ['GBP'],
            'kzt' => ['KZT'],
            'uah' => ['UAH'],
            'cny' => ['CNY'],
        ];
    }

    public function testMissingCurrencyRejected(): void
    {
        $result = $this->validator->validate(['storeId' => 1]);

        self::assertFalse($result->isValid());
        self::assertContains('CURRENCY_NOT_SUPPORTED', $result->getErrorCodes());
    }
}
