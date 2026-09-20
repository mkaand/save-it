<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class RedisRateLimit
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        $limit = max(1, (int) config("services.abuse_limits.{$bucket}", 60));
        $key = 'save-it:rate:v1:'.$bucket.':'.$request->ip();

        try {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                return $this->limited($retryAfter);
            }
            RateLimiter::hit($key, 60);
        } catch (\Throwable $exception) {
            // Availability takes priority during a transient Redis outage; do
            // not turn anonymous reads into a global outage.
            Log::warning('rate_limit_store_unavailable', ['bucket' => $bucket]);
        }

        return $next($request);
    }

    private function limited(int $retryAfter): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'rate_limited',
                'message' => 'Too many requests. Please try again shortly.',
            ],
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
