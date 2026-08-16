<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps the size of a write. Content-Length is checked first because it is
 * free, but it is client-supplied and may be absent (chunked transfer) or a
 * lie, so the actual body length is checked too.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('kvstore.max_body_bytes');

        $declared = $request->headers->get('Content-Length');
        $actual = strlen($request->getContent());

        if ((int) $declared > $max || $actual > $max) {
            return response()->json([
                'message' => "Request body must not exceed {$max} bytes.",
            ], 413);
        }

        return $next($request);
    }
}
