<?php

declare(strict_types=1);

namespace App\Services\Payment\Gateways\Exceptions;

use RuntimeException;

class GatewayException extends RuntimeException
{
    /** @param  array<string, mixed>|null  $payload */
    public function __construct(
        string $message,
        public readonly string $gateway = 'pesapal',
        public readonly ?array $payload = null,
    ) {
        parent::__construct($message);
    }
}