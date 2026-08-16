<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stored values are attacker-controlled and may contain markup, so responses
 * are hardened twice over: the browser is told never to sniff a content type
 * out of the body, and JSON bodies are encoded with '<', '>', '&', '"' and
 * "'" escaped to their \uXXXX forms. Either measure alone would do; together
 * a stored <script> payload cannot be rendered as markup even if the response
 * is served or embedded somewhere unexpected.
 */
class SecurityHeaders
{
    private const JSON_ESCAPES = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * Deny by default, then open only what the page actually uses. There is no
     * 'unsafe-inline' anywhere, which is the whole point: the stylesheet and
     * script were moved out of the Blade template into public/ so that an
     * injected <script> — inline by definition — simply will not run.
     *
     * base-uri blocks a <base> tag from rewriting the relative script URL,
     * form-action leaves nothing to submit to, and frame-ancestors is the
     * modern form of the X-Frame-Options above.
     */
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

        // isEmpty() covers 204/304, whose bodies must stay absent.
        if ($response instanceof JsonResponse && ! $response->isEmpty()) {
            $response->setEncodingOptions($response->getEncodingOptions() | self::JSON_ESCAPES);
        }

        return $response;
    }
}
