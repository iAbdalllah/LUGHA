<?php

namespace App\Domain\Devices;

use RuntimeException;

class DeviceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
