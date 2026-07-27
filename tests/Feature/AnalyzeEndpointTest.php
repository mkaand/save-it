<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyzeEndpointTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function supportedUrlProvider(): array
    {
        return [
            'YouTube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'YouTube'],
            'YouTube short link' => ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'YouTube'],
            'YouTube Shorts' => ['https://www.youtube.com/shorts/abc123DEF45', 'youtube_shorts', 'YouTube Shorts'],
            'Instagram' => ['https://www.instagram.com/p/example/', 'instagram', 'Instagram'],
            'TikTok' => ['https://www.tiktok.com/@creator/video/123456', 'tiktok', 'TikTok'],
            'X' => ['https://x.com/saveit/status/123456', 'x', 'X'],
            'Twitter' => ['https://twitter.com/saveit/status/123456', 'x', 'X'],
            'Facebook' => ['https://www.facebook.com/watch/?v=123456', 'facebook', 'Facebook'],
            'LinkedIn' => ['https://www.linkedin.com/posts/example', 'linkedin', 'LinkedIn'],
        ];
    }

    #[DataProvider('supportedUrlProvider')]
    public function test_it_analyzes_supported_urls(string $url, string $platform, string $label): void
    {
        $this->postJson('/api/analyze', ['url' => $url])
            ->assertOk()
            ->assertJsonPath('data.platform', $platform)
            ->assertJsonPath('data.platform_label', $label)
            ->assertJsonPath('data.status', 'preview')
            ->assertJsonStructure([
                'data' => [
                    'platform',
                    'platform_label',
                    'media_type',
                    'url',
                    'title',
                    'thumbnail_url',
                    'status',
                    'outputs' => [
                        '*' => ['id', 'label', 'detail', 'available'],
                    ],
                ],
            ]);
    }

    public function test_it_accepts_a_normal_form_request_and_returns_json(): void
    {
        $this->post('/api/analyze', [
            'url' => 'https://www.instagram.com/p/example/',
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.platform', 'instagram');
    }

    public function test_it_normalizes_youtube_urls_and_derives_only_a_valid_thumbnail(): void
    {
        $this->postJson('/api/analyze', [
            'url' => 'http://youtu.be/dQw4w9WgXcQ?feature=shared#fragment',
        ])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.thumbnail_url', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');

        $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/watch?v=invalid',
        ])
            ->assertOk()
            ->assertJsonPath('data.thumbnail_url', null);
    }

    public function test_no_preview_output_claims_to_be_available(): void
    {
        $response = $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertOk();

        foreach ($response->json('data.outputs') as $output) {
            $this->assertFalse($output['available']);
            $this->assertArrayNotHasKey('size', $output);
            $this->assertArrayNotHasKey('download_url', $output);
        }

        $content = $response->getContent();
        $this->assertStringNotContainsString('APP_KEY', $content);
        $this->assertStringNotContainsString('stack', strtolower($content));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'missing URL' => [[]],
            'invalid URL' => [['url' => 'not a url']],
            'unsupported scheme' => [['url' => 'ftp://youtube.com/video']],
            'unsupported hostname' => [['url' => 'https://example.com/video']],
            'hostname suffix attack' => [['url' => 'https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ']],
            'lookalike hostname' => [['url' => 'https://notyoutube.com/watch?v=dQw4w9WgXcQ']],
            'embedded credentials' => [['url' => 'https://user:password@youtube.com/watch?v=dQw4w9WgXcQ']],
            'localhost' => [['url' => 'http://localhost/video']],
            'IPv4 loopback' => [['url' => 'http://127.0.0.1/video']],
            'private IPv4' => [['url' => 'http://192.168.1.20/video']],
            'IPv6 loopback' => [['url' => 'http://[::1]/video']],
            'private IPv6' => [['url' => 'http://[fc00::1]/video']],
            'IPv6 link local' => [['url' => 'http://[fe80::1]/video']],
            'IPv4 link local' => [['url' => 'http://169.254.1.1/video']],
            'custom port' => [['url' => 'https://youtube.com:8443/watch?v=dQw4w9WgXcQ']],
        ];
    }

    #[DataProvider('rejectedUrlProvider')]
    public function test_it_rejects_unsafe_or_unsupported_urls(array $payload): void
    {
        $this->postJson('/api/analyze', $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['url'],
            ]);
    }

    public function test_rate_limit_returns_json_after_thirty_requests(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.77'];
        $payload = ['url' => 'https://www.instagram.com/p/rate-limit-test/'];

        for ($request = 1; $request <= 30; $request++) {
            $this->withServerVariables($server)
                ->postJson('/api/analyze', $payload)
                ->assertOk();
        }

        $this->withServerVariables($server)
            ->postJson('/api/analyze', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/json');
    }
}
