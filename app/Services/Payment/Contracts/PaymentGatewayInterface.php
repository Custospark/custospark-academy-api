<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /** @param  array<string, mixed>  $payload */
    public function initiate(array $payload): array;

    /** @return array<string, mixed> */
    public function verify(string $transactionId): array;

    /** @return array<string, mixed> */
    public function parseWebhookPayload(Request $request): array;

    public function verifyWebhookSignature(Request $request): bool;

    public function getName(): string;

    public function isEnabled(): bool;

    /** @return list<string> */
    public function getSupportedCurrencies(): array;

    public function isRedirectBased(): bool;
}