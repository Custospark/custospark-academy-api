<?php

return [
    'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),

    'consumer_key' => env('PESAPAL_ENVIRONMENT') === 'production'
        ? env('PESAPAL_PRODUCTION_CONSUMER_KEY')
        : env('PESAPAL_SANDBOX_CONSUMER_KEY'),
    'consumer_secret' => env('PESAPAL_ENVIRONMENT') === 'production'
        ? env('PESAPAL_PRODUCTION_CONSUMER_SECRET')
        : env('PESAPAL_SANDBOX_CONSUMER_SECRET'),

    'base_url_sandbox' => 'https://cybqa.pesapal.com/pesapalv3',
    'base_url_production' => 'https://pay.pesapal.com/v3',

    'callback_url' => env('PESAPAL_CALLBACK_URL', 'http://localhost:8000/api/v1/payments/pesapal/callback'),
    'ipn_url' => env('PESAPAL_IPN_URL', 'http://localhost:8000/api/v1/payments/pesapal/ipn'),

    'enabled' => env('PESAPAL_ENABLED', false),
    'bypass' => env('PESAPAL_BYPASS', false),
    'token_cache_ttl' => env('PESAPAL_TOKEN_CACHE_TTL', 1800),
    'ipn_id' => env('PESAPAL_IPN_ID'),
];