<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stored values are attacker-controlled and may contain markup, so responses
 * are hardened twice over: the browser is told never to sniff a content type
 * out of the body, and JSON bodies escape '<', '>', '&', '"' and "'" to their
 * \uXXXX forms. Either alone would do; together a stored <script> payload
 * cannot be rendered as markup even if the response is embedded somewhere
 * unexpected.
 *
 * The CSP denies by default and opens only what the page uses. There is no
 * 'unsafe-inline' anywhere — the stylesheet and script were moved out of the
 * Blade template into public/ precisely so an injected <script>, inline by
 * definition, will not run.
 */
class SecurityHeaders
{
    private const JSON_ESCAPES = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private const CONTENT_SECURITY_POLICY = [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self'",
        "connect-src 'self'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Content-Security-Policy',
            implode('; ', self::CONTENT_SECURITY_POLICY)
        );

        if ($response instanceof JsonResponse && ! $response->isEmpty()) {
            $response->setEncodingOptions($response->getEncodingOptions() | self::JSON_ESCAPES);
        }

        return $response;
    }
}
