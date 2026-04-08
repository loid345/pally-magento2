<?php

declare(strict_types=1);

namespace Pally\Payment\Exception;

/**
 * Thrown by the webhook processor when the order referenced by a notify
 * payload cannot be located. Maps to HTTP 404 in the webhook controller
 * so Pally stops redelivering a request that will never be processable.
 */
class WebhookOrderNotFoundException extends \RuntimeException
{
}
