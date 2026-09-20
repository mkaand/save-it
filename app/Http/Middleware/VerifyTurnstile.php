<?php

namespace App\Http\Middleware;

use App\Services\Settings\Turnstile;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTurnstile
{
    public function __construct(private readonly Turnstile $turnstile) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->turnstile->verify($request)) {
            return new JsonResponse(['error' => ['code' => 'challenge_failed', 'message' => 'Security verification failed. Please try again.']], 422);
        }

        return $next($request);
    }
}
