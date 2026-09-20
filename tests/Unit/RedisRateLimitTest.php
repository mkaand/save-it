<?php

namespace Tests\Unit;

use App\Http\Middleware\RedisRateLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RedisRateLimitTest extends TestCase
{
    #[Test]
    public function buckets_are_isolated_and_return_retry_after(): void
    {
        config(['services.abuse_limits.analyze' => 1, 'services.abuse_limits.share' => 1]);
        $request = Request::create('/api/analyze', 'POST', server: ['REMOTE_ADDR' => '198.51.100.88']);
        $next = static fn (): Response => response()->json(['ok' => true]);
        $limiter = app(RedisRateLimit::class);

        $this->assertSame(200, $limiter->handle($request, $next, 'analyze')->getStatusCode());
        $limited = $limiter->handle($request, $next, 'analyze');
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertNotEmpty($limited->headers->get('Retry-After'));
        $this->assertSame(200, $limiter->handle($request, $next, 'share')->getStatusCode());

        RateLimiter::clear('save-it:rate:v1:analyze:198.51.100.88');
        RateLimiter::clear('save-it:rate:v1:share:198.51.100.88');
    }
}
