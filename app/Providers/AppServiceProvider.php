<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Two things the framework will not do on its own.
 *
 * Method override is switched off outright: Laravel copies a decoded JSON
 * body into the same Symfony bag that _method is read from, so a body
 * property named _method could turn a POST into a DELETE. Nothing here uses
 * spoofing, and "_method" becomes an ordinary storable key again.
 *
 * The rate limiter is what makes throttleApi() in bootstrap/app.php do
 * anything — Laravel 11+ throttles no API route until a limiter is named.
 * Its window is rolling, so the caller is told the seconds actually left
 * rather than "wait a minute".
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        SymfonyRequest::setAllowedHttpMethodOverride([]);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('kvstore.max_requests_per_minute'))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    $seconds = (int) ($headers['Retry-After'] ?? 60);

                    return response()->json([
                        'message' => "Too many requests. Try again in {$seconds} second"
                            .($seconds === 1 ? '' : 's').'.',
                        'retry_after' => $seconds,
                    ], 429, $headers);
                });
        });
    }
}
