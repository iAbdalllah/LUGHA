<?php

namespace App\Domain\Delivery;

use RuntimeException;

class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
