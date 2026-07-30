<?php

namespace Tests\Unit;

use App\Services\Downloads\DownloadException;
use App\Services\Downloads\UpstreamUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpstreamUrlPolicyTest extends TestCase
{
    public static function unsafeUrlProvider(): array
    {
        return [
            'non HTTPS' => ['http://video.twimg.com/file.mp4', 'x'],
            'credentials' => ['https://user:pass@video.twimg.com/file.mp4', 'x'],
            'custom port' => ['https://video.twimg.com:8443/file.mp4', 'x'],
            'suffix attack' => ['https://video.twimg.com.evil.example/file.mp4', 'x'],
            'bare suffix' => ['https://licdn.com/file.mp4', 'linkedin'],
            'wrong provider' => ['https://dms.licdn.com/file.mp4', 'instagram'],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_it_rejects_unsafe_provider_urls(string $url, string $provider): void
    {
        $this->expectException(DownloadException::class);

        (new UpstreamUrlPolicy)->validate($url, $provider, false);
    }

    public function test_it_accepts_exact_or_controlled_suffix_hosts(): void
    {
        $policy = new UpstreamUrlPolicy;

        $this->assertSame(
            'https://video.twimg.com/file.mp4',
            $policy->validate('https://video.twimg.com/file.mp4', 'x', false),
        );
        $this->assertSame(
            'https://dms.licdn.com/file.mp4',
            $policy->validate('https://dms.licdn.com/file.mp4', 'linkedin', false),
        );
        $this->assertSame(
            'https://rr1.googlevideo.com/file',
            $policy->validate('https://rr1.googlevideo.com/file', 'youtube', false),
        );
    }

    public function test_it_rejects_private_dns_answers_before_fetching(): void
    {
        $policy = new class extends UpstreamUrlPolicy
        {
            protected function resolveAddresses(string $host): array
            {
                return ['127.0.0.1', 'fc00::1'];
            }
        };

        try {
            $policy->validate('https://video.twimg.com/file.mp4', 'x');
            $this->fail('A private DNS result was accepted.');
        } catch (DownloadException $exception) {
            $this->assertSame('unsafe_media_source', $exception->publicCode);
        }
    }
}
