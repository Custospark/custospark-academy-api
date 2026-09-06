<?php

declare(strict_types=1);

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PesaPal v3 gateway for Custospark Academy. Mirrors the Custosell integration
 * (token auth, IPN registration, order submission, status verification) but
 * simplified for the Academy's few payment types.
 */
class PesaPalGateway implements PaymentGatewayInterface
{
    private string $baseUrl;

    private string $consumerKey;

    private string $consumerSecret;

    private string $ipnId;

    public function __construct()
    {
        $cfg = config('pesapal');

        $env = $cfg['environment'] ?? 'sandbox';
        $this->baseUrl = $env === 'production'
            ? (string) $cfg['base_url_production']
            : (string) $cfg['base_url_sandbox'];

        $this->consumerKey = (string) ($cfg['consumer_key'] ?? '');
        $this->consumerSecret = (string) ($cfg['consumer_secret'] ?? '');
        $this->ipnId = (string) ($cfg['ipn_id'] ?? '');
    }

    public function getName(): string
    {
        return 'pesapal';
    }

    public function isRedirectBased(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function getSupportedCurrencies(): array
    {
        return ['UGX', 'KES', 'TZS', 'USD'];
    }

    public function isEnabled(): bool
    {
        if ($this->isBypassMode()) {
            return true;
        }

        return filter_var(config('pesapal.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $this->consumerKey !== ''
            && $this->consumerSecret !== '';
    }

    /** @param  array<string, mixed>  $payload */
    public function initiate(array $payload): array
    {
        if ($this->isBypassMode()) {
            $ref = 'CSA-'.$payload['payment_id'].'-'.now()->format('YmdHis');
            Log::info('[PesaPal] Bypass mode - payment auto-approved', [
                'payment_id' => $payload['payment_id'],
                'reference' => $ref,
            ]);

            return [
                'success' => true,
                'gateway_ref' => $ref,
                'gateway_txn_id' => 'BYPASS-'.$payload['payment_id'],
                'redirect_url' => null,
                'type' => 'bypass',
                'message' => 'Payment bypassed (development mode).',
                'raw_response' => ['bypass' => true],
            ];
        }

        $merchantRef = 'CSA-'.$payload['payment_id'].'-'.now()->format('YmdHis');
        $accessToken = $this->getAccessToken();
        $ipnId = $this->ipnId !== '' ? $this->ipnId : $this->registerIpn($accessToken);

        $body = [
            'id' => $merchantRef,
            'currency' => strtoupper((string) ($payload['currency'] ?? 'UGX')),
            'amount' => (float) $payload['amount'],
            'description' => (string) ($payload['description'] ?? 'Custospark Academy payment'),
            'callback_url' => (string) config('pesapal.callback_url'),
            'redirect_mode' => 'TOP_WINDOW',
            'notification_id' => $ipnId,
            'billing_address' => [
                'email_address' => $payload['email'] ?? 'noreply@custospark.com',
                'phone_number' => $payload['phone_number'] ?? '',
                'country_code' => $payload['country_code'] ?? 'UG',
                'first_name' => $payload['customer_name'] ?? 'Academy Learner',
                'last_name' => '',
            ],
        ];

        $response = $this->postOrder($accessToken, $body);

        if ($response->status() === 401) {
            // The cached Bearer token died before PesaPal's own expiry (or was
            // revoked/rotated). Drop it, fetch a fresh one, retry exactly once.
            Cache::forget($this->tokenCacheKey());
            Log::warning('[PesaPal] Order submission got 401 - refreshing token and retrying once');
            $accessToken = $this->getAccessToken();
            $response = $this->postOrder($accessToken, $body);
        }

        $data = $response->json() ?? [];

        if (! $response->successful() || empty($data['redirect_url'])) {
            Log::error('[PesaPal] Order submission failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'data' => $data,
            ]);
            $detail = $this->extractError($data) ?? "HTTP {$response->status()}";
            throw new GatewayException("PesaPal order submission failed: {$detail}", 'pesapal', $data);
        }

        Log::info('[PesaPal] Order submitted', [
            'order_tracking_id' => $data['order_tracking_id'],
            'merchant_reference' => $merchantRef,
        ]);

        return [
            'success' => true,
            'gateway_ref' => $merchantRef,
            'gateway_txn_id' => $data['order_tracking_id'],
            'redirect_url' => $data['redirect_url'],
            'type' => 'redirect',
            'message' => 'Redirecting to PesaPal payment page.',
            'raw_response' => $data,
        ];
    }

    /** @return array<string, mixed> */
    public function verify(string $transactionId): array
    {
        if ($this->isBypassMode()) {
            return [
                'success' => true,
                'status' => 'successful',
                'gateway_txn_id' => $transactionId,
                'amount' => 0,
                'currency' => '',
                'message' => 'Bypass mode - auto-verified.',
                'raw_response' => ['bypass' => true],
            ];
        }

        $accessToken = $this->getAccessToken();
        $response = $this->fetchOrderStatus($accessToken, $transactionId);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            Log::warning('[PesaPal] Status check got 401 - refreshing token and retrying once');
            $accessToken = $this->getAccessToken();
            $response = $this->fetchOrderStatus($accessToken, $transactionId);
        }

        $data = $response->json() ?? [];
        $statusCode = (int) ($data['status_code'] ?? 0);

        $status = match ($statusCode) {
            1 => 'successful',
            2, 3 => 'failed',
            default => 'pending',
        };

        return [
            'success' => $status === 'successful',
            'status' => $status,
            'gateway_txn_id' => $transactionId,
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => $data['currency'] ?? '',
            'message' => $status === 'successful'
                ? 'Payment completed successfully.'
                : ($data['payment_status_description'] ?? 'Payment status unknown.'),
            'raw_response' => $data,
        ];
    }

    /** @return array<string, mixed> */
    public function parseWebhookPayload(Request $request): array
    {
        return [
            'gateway_txn_id' => $request->query('OrderTrackingId', ''),
            'our_reference' => $request->query('OrderMerchantReference', ''),
            'status' => 'pending',
            'amount' => 0,
            'currency' => '',
            'raw_payload' => $request->query(),
        ];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }

    private function tokenCacheKey(): string
    {
        return 'pesapal_token_'.config('pesapal.environment');
    }

    /** @param  array<string, mixed>  $body */
    private function postOrder(string $accessToken, array $body): Response
    {
        return Http::withToken($accessToken)
            ->asJson()
            ->acceptJson()
            ->timeout(30)
            ->post("{$this->baseUrl}/api/Transactions/SubmitOrderRequest", $body);
    }

    private function fetchOrderStatus(string $accessToken, string $transactionId): Response
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get("{$this->baseUrl}/api/Transactions/GetTransactionStatus", [
                'orderTrackingId' => $transactionId,
            ]);
    }

    private function isBypassMode(): bool
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            return false;
        }

        return filter_var(config('pesapal.bypass', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function getAccessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $ttl = (int) config('pesapal.token_cache_ttl', 1800);

        return Cache::remember($cacheKey, $ttl, function () {
            $response = Http::acceptJson()
                ->contentType('application/json')
                ->timeout(15)
                ->post("{$this->baseUrl}/api/Auth/RequestToken", [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);

            $data = $response->json() ?? [];

            if ($response->successful() && ! empty($data['token'])) {
                return $data['token'];
            }

            Log::error('[PesaPal] Token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $detail = $this->extractError($data) ?? "HTTP {$response->status()} (no token - check PesaPal credentials)";

            throw new GatewayException("PesaPal token request failed: {$detail}", 'pesapal', $data);
        });
    }

    private function registerIpn(string $accessToken): string
    {
        $ipnUrl = (string) (config('pesapal.ipn_url') ?? route('payments.pesapal.ipn'));

        $response = Http::withToken($accessToken)
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post("{$this->baseUrl}/api/URLSetup/RegisterIPN", [
                'url' => $ipnUrl,
                'ipn_notification_type' => 'GET',
            ]);

        $data = $response->json() ?? [];

        if (! $response->successful() || empty($data['ipn_id'])) {
            throw new GatewayException(
                'PesaPal IPN registration failed: '.($data['message'] ?? "HTTP {$response->status()}"),
                'pesapal',
                $data
            );
        }

        Log::info('[PesaPal] IPN registered', ['ipn_id' => $data['ipn_id'], 'url' => $ipnUrl]);

        return $data['ipn_id'];
    }

    /** @param  array<string, mixed>  $data */
    private function extractError(array $data): ?string
    {
        foreach (['error', 'message', 'error_message'] as $key) {
            $val = $data[$key] ?? null;
            if (is_string($val) && $val !== '') {
                return $val;
            }
            if (is_array($val)) {
                foreach (['message', 'error', 'description'] as $sub) {
                    if (isset($val[$sub]) && is_string($val[$sub]) && $val[$sub] !== '') {
                        return $val[$sub];
                    }
                }

                return json_encode($val);
            }
        }

        return null;
    }
}