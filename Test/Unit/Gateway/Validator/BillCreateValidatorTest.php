<?php

declare(strict_types=1);

namespace Pally\Payment\Test\Unit\Gateway\Validator;

use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Pally\Payment\Gateway\Validator\BillCreateValidator;
use PHPUnit\Framework\TestCase;

class BillCreateValidatorTest extends TestCase
{
    private BillCreateValidator $validator;

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

        $this->validator = new BillCreateValidator($factory);
    }

    public function testValidResponseAccepted(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => true,
                'bill_id' => 'abc123',
                'link_page_url' => 'https://pay.example.com/abc123',
            ],
        ]);

        self::assertTrue($result->isValid());
    }

    public function testStringTrueAccepted(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => 'true',
                'bill_id' => 'abc123',
                'link_page_url' => 'https://pay.example.com/abc123',
            ],
        ]);

        self::assertTrue($result->isValid());
    }

    public function testBooleanFalseRejected(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => false,
                'bill_id' => 'abc123',
                'link_page_url' => 'https://pay.example.com/abc123',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertContains('API_ERROR', $result->getErrorCodes());
    }

    public function testStringFalseRejected(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => 'false',
                'bill_id' => 'abc123',
                'link_page_url' => 'https://pay.example.com/abc123',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertContains('API_ERROR', $result->getErrorCodes());
    }

    public function testMissingBillIdRejected(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => true,
                'link_page_url' => 'https://pay.example.com/abc123',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertContains('INVALID_RESPONSE', $result->getErrorCodes());
    }

    public function testMissingLinkPageUrlRejected(): void
    {
        $result = $this->validator->validate([
            'response' => [
                'success' => true,
                'bill_id' => 'abc123',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertContains('INVALID_RESPONSE', $result->getErrorCodes());
    }
}
