<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * Only try-on pages may be framed by the configured store origins, and only
     * those origins (plus this site) may be granted the camera.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $embedOrigins = $request->routeIs('try-on.*') ? config('tryon.embed_origins') : [];
        $quotedOrigins = array_map(fn (string $origin) => '"'.$origin.'"', $embedOrigins);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', trim("frame-ancestors 'self' ".implode(' ', $embedOrigins)));
        $response->headers->set('Permissions-Policy', 'camera=('.trim('self '.implode(' ', $quotedOrigins)).'), microphone=(), geolocation=()');

        return $response;
    }
}
