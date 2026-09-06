<?php

use App\Exceptions\DomainException;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        // Payment-provider outages are downstream failures, not app bugs:
        // answer 502 with a safe message, keep the detail in the log.
        $exceptions->render(function (GatewayException $e) {
            return response()->json(
                ['message' => 'The payment provider is unavailable. Please try again in a moment.'],
                502
            );
        });
    })->create();
