<?php

declare(strict_types=1);

namespace Pally\Payment\Exception;

/**
 * Thrown by the webhook processor when the per-order lock cannot be
 * acquired within the configured timeout. Maps to HTTP 503 in the
 * webhook controller so Pally retries the delivery after another
 * worker has released the lock.
 */
class WebhookLockException extends \RuntimeException
{
}
