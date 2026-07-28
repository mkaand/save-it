<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyzeEndpointTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function supportedUrlProvider(): array
    {
        return [
            'YouTube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'YouTube', 'ready'],
            'YouTube short link' => ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'YouTube', 'ready'],
            'YouTube Shorts' => ['https://www.youtube.com/shorts/abc123DEF45', 'youtube_shorts', 'YouTube Shorts', 'ready'],
            'Instagram' => ['https://www.instagram.com/p/example/', 'instagram', 'Instagram', 'preview'],
            'TikTok' => ['https://www.tiktok.com/@creator/video/123456', 'tiktok', 'TikTok', 'preview'],
            'X' => ['https://x.com/saveit/status/123456', 'x', 'X', 'preview'],
            'Twitter' => ['https://twitter.com/saveit/status/123456', 'x', 'X', 'preview'],
            'Facebook' => ['https://www.facebook.com/watch/?v=123456', 'facebook', 'Facebook', 'preview'],
            'LinkedIn' => ['https://www.linkedin.com/posts/example', 'linkedin', 'LinkedIn', 'preview'],
        ];
    }

    #[DataProvider('supportedUrlProvider')]
    public function test_it_analyzes_supported_urls(
        string $url,
        string $platform,
        string $label,
        string $status,
    ): void {
        $this->fakeRecognition($url, $platform);

        $this->postJson('/api/analyze', ['url' => $url])
            ->assertOk()
            ->assertJsonPath('data.platform', $platform)
            ->assertJsonPath('data.platform_label', $label)
            ->assertJsonPath('data.status', $status)
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
        $this->fakeRecognition(
            'https://www.instagram.com/p/example/',
            'instagram',
        );

        $this->post('/api/analyze', [
            'url' => 'https://www.instagram.com/p/example/',
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.platform', 'instagram');
    }

    public function test_it_normalizes_youtube_urls(): void
    {
        $this->fakeRecognition(
            'https://youtu.be/dQw4w9WgXcQ?feature=shared#fragment',
            'youtube',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        );

        $this->postJson('/api/analyze', [
            'url' => 'https://youtu.be/dQw4w9WgXcQ?feature=shared#fragment',
        ])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.thumbnail_url', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg');
    }

    public function test_it_rejects_invalid_youtube_video_ids(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'invalid_youtube_video_url',
                    'message' => 'Internal wording.',
                    'request_id' => 'youtube-error',
                    'details' => ['provider' => 'youtube'],
                ],
            ], 422),
        ]);

        $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/watch?v=invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'Enter a valid YouTube video or Shorts URL.');
    }

    public function test_it_rejects_playlist_only_youtube_urls(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'playlist_not_supported',
                    'message' => 'Internal wording.',
                    'request_id' => 'youtube-error',
                    'details' => ['provider' => 'youtube'],
                ],
            ], 422),
        ]);

        $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/playlist?list=PLexample',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.url.0',
                'YouTube playlists are not supported. Submit a single video URL.',
            );
    }

    public function test_it_rejects_live_youtube_urls(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'live_not_supported',
                    'message' => 'Internal wording.',
                    'request_id' => 'youtube-error',
                    'details' => ['provider' => 'youtube'],
                ],
            ], 422),
        ]);

        $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.url.0',
                'YouTube live and scheduled live videos are not supported.',
            );
    }

    public function test_no_preview_output_claims_to_be_available(): void
    {
        $this->fakeRecognition(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'youtube',
        );

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
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'unsupported_host',
                    'message' => 'The submitted host is not supported.',
                    'request_id' => 'extractor-validation',
                    'details' => [],
                ],
            ], 422),
        ]);

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
        $this->fakeRecognition($payload['url'], 'instagram');

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

    private function fakeRecognition(
        string $sourceUrl,
        string $platform,
        ?string $normalizedUrl = null,
    ): void {
        $provider = $platform === 'youtube_shorts' ? 'youtube' : $platform;
        $variant = $platform === 'youtube_shorts' ? 'shorts' : null;

        Http::fake(function (Request $request) use (
            $sourceUrl,
            $provider,
            $variant,
            $normalizedUrl,
        ) {
            $requestedUrl = $request->data()['url'];
            $requestId = $request->data()['request_id'];
            $effectiveUrl = $requestedUrl === $sourceUrl
                ? ($normalizedUrl ?? $sourceUrl)
                : $requestedUrl;

            if ($provider === 'youtube') {
                return Http::response(
                    $this->youtubeSuccessResponse($requestId, $effectiveUrl, $variant),
                );
            }

            return Http::response([
                'error' => [
                    'code' => 'provider_not_implemented',
                    'message' => 'The provider is recognized, but extraction is not implemented yet.',
                    'request_id' => $requestId,
                    'details' => [
                        'provider' => $provider,
                        'provider_label' => ucfirst($provider),
                        'provider_variant' => $variant,
                        'media_type' => 'unknown',
                        'normalized_url' => $effectiveUrl,
                        'status' => 'not_implemented',
                        'metadata' => null,
                        'assets' => [],
                        'capabilities' => [],
                    ],
                ],
            ], 501);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeSuccessResponse(
        string $requestId,
        string $normalizedUrl,
        ?string $variant,
    ): array {
        preg_match('/(?:v=|shorts\/)([A-Za-z0-9_-]{11})/', $normalizedUrl, $matches);
        $videoId = $matches[1] ?? 'dQw4w9WgXcQ';
        $canonical = $variant === 'shorts'
            ? "https://www.youtube.com/shorts/{$videoId}"
            : "https://www.youtube.com/watch?v={$videoId}";

        return [
            'data' => [
                'request_id' => $requestId,
                'provider' => 'youtube',
                'provider_label' => 'YouTube',
                'provider_variant' => $variant ?? 'video',
                'media_type' => $variant === 'shorts' ? 'short_video' : 'video',
                'source_url' => $normalizedUrl,
                'normalized_url' => $canonical,
                'status' => 'ready',
                'metadata' => [
                    'video_id' => $videoId,
                    'title' => 'Public YouTube video',
                    'author_name' => 'Example Channel',
                    'author_handle' => 'UCexample',
                    'duration_ms' => 212000,
                    'thumbnail_url' => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
                    'thumbnails' => [[
                        'url' => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
                        'width' => 1280,
                        'height' => 720,
                        'preference' => 10,
                    ]],
                    'video_formats' => [[
                        'format_id' => '137',
                        'container' => 'mp4',
                        'video_codec' => 'avc1.640028',
                        'video_codec_family' => 'h264',
                        'audio_codec' => null,
                        'width' => 1920,
                        'height' => 1080,
                        'resolution' => '1920×1080',
                        'fps' => 30,
                        'bitrate_kbps' => 2500,
                        'estimated_filesize' => 55000000,
                        'has_audio' => false,
                        'requires_merge' => true,
                        'preference' => 0,
                    ]],
                    'audio_formats' => [[
                        'format_id' => '140',
                        'container' => 'm4a',
                        'audio_codec' => 'mp4a.40.2',
                        'bitrate_kbps' => 129,
                        'sample_rate_hz' => 44100,
                        'estimated_filesize' => 3400000,
                        'language' => null,
                        'preference' => 0,
                    ]],
                    'conversion_plans' => [[
                        'id' => 'mp3',
                        'label' => 'MP3',
                        'source' => 'audio_format',
                        'requires_ffmpeg' => true,
                        'available' => false,
                    ]],
                ],
                'assets' => [],
                'capabilities' => [
                    'metadata',
                    'thumbnails',
                    'video_formats',
                    'audio_formats',
                    'conversion_plans',
                ],
            ],
        ];
    }
}
