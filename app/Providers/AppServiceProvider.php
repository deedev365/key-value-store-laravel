<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * EloquentKeyValueRepository is not registered here: it has no
     * constructor dependencies, so the container resolves it on its own.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel copies a decoded JSON body into the Symfony request bag,
        // which is the same bag Symfony reads _method from when resolving the
        // HTTP verb. A body property (or query parameter) named _method could
        // therefore turn a POST into a DELETE. Nothing here uses method
        // spoofing — there are no HTML forms — so the override is turned off
        // outright and "_method" becomes an ordinary storable key again.
        SymfonyRequest::setAllowedHttpMethodOverride([]);

        $this->configureRateLimiting();
    }

    /**
     * Laravel 11+ does not throttle API routes unless a limiter is named, so
     * this is what makes throttleApi() in bootstrap/app.php do anything.
     *
     * The window is a rolling one: the caller is told how many seconds are
     * actually left rather than "wait a minute", because a limit reached late
     * in the window clears in seconds, not in sixty of them.
     */
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
