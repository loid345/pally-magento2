<?php

declare(strict_types=1);

namespace Magento\Sales\Model;

/**
 * Minimal stub of Magento\Sales\Model\Order for unit tests that only need the
 * STATE_* constants and run without a Magento install. The real Magento class
 * fully shadows this one when the framework is autoloadable.
 */
class Order
{
    public const STATE_NEW = 'new';
    public const STATE_PENDING_PAYMENT = 'pending_payment';
    public const STATE_PROCESSING = 'processing';
    public const STATE_COMPLETE = 'complete';
    public const STATE_CLOSED = 'closed';
    public const STATE_CANCELED = 'canceled';
    public const STATE_HOLDED = 'holded';
    public const STATE_PAYMENT_REVIEW = 'payment_review';
}
