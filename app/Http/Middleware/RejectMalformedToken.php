<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class RejectMalformedToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token') ?? $request->input('token');
        if (! is_string($token) || preg_match('/^[a-z0-9]{48}\.[a-f0-9]{64}$/', $token) !== 1) {
            $key = 'save-it:invalid-token:v1:'.$request->ip();
            $limit = max(1, (int) config('services.abuse_limits.invalid_token', 15));
            try {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    return response()->json(['error' => [
                        'code' => 'rate_limited',
                        'message' => 'Too many requests. Please try again shortly.',
                    ]], 429, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
                }
                RateLimiter::hit($key, 60);
            } catch (\Throwable) {
                // The surrounding endpoint policy remains available if Redis is transiently down.
            }

            return response()->json(['error' => [
                'code' => 'download_token_expired',
                'message' => 'This download link has expired. Analyze the URL again.',
            ]], 410);
        }

        return $next($request);
    }
}
