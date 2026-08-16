<?php

use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * The API's own URLs. Several framework behaviours below key off the request
 * path rather than the matched route, so they need this spelled out — the
 * routes are mounted at the root (see apiPrefix), not under a prefix that
 * could be matched with a single wildcard.
 */
$isApi = fn (Request $request) => $request->is('object', 'object/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The exercise specifies /object, /object/{key} and
        // /object/get_all_records; Laravel would otherwise mount them under
        // its default /api prefix.
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware) use ($isApi): void {
        // Laravel's global TrimStrings/ConvertEmptyStringsToNull middleware
        // rewrites the parsed JSON body, so a stored value would silently
        // change on the way in: "  a  " became "a" and "" became null. A
        // key-value store must persist values byte-for-byte, so both are
        // skipped for the API.
        $middleware->trimStrings(except: [$isApi]);
        $middleware->convertEmptyStringsToNull(except: [$isApi]);

        $middleware->append(SecurityHeaders::class);

        // throttleApi() puts 'throttle:api' at the head of the API group, so
        // LimitRequestBody is appended rather than prepended: a caller who is
        // over their quota is turned away before the body is looked at.
        $middleware->throttleApi('api');
        $middleware->api(append: [LimitRequestBody::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApi): void {
        $exceptions->shouldRenderJsonWhen($isApi);
    })->create();
