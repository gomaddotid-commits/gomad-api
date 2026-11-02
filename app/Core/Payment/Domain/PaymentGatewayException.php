<?php

namespace App\Core\Payment\Domain;

use RuntimeException;
use Throwable;

final class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
