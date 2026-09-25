<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow large video/file uploads up to 1 hour.
 *
 * Shared hosting caps PHP at 30-300s by default, which drops slow lesson
 * uploads mid-stream. This lifts the PHP-side limits for the request; the
 * .user.ini (max_execution_time/max_input_time = 3600) covers the general
 * case and the web-server idle timeout (nginx fastcgi_read_timeout / Apache
 * Timeout, see DEPLOYMENT.md) must also allow 3600s.
 */
class EnsureLongUploadTimeout
{
    public const SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        @set_time_limit(self::SECONDS);
        @ini_set('max_execution_time', (string) self::SECONDS);
        @ini_set('max_input_time', (string) self::SECONDS);

        return $next($request);
    }
}
