<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExtractorClientTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, array<string, mixed>>}>
     */
    public static function xMediaProvider(): array
    {
        $image = self::imageAsset();
        $video = self::videoAsset();

        return [
            'single image' => ['image', [$image]],
            'single video' => ['video', [$video]],
            'image carousel' => ['carousel', [$image, self::imageAsset(2)]],
            'mixed media' => ['mixed_media', [$video, self::imageAsset(2)]],
            'animated GIF transport' => ['animated_gif', [self::videoAsset('animated_gif')]],
        ];
    }

    #[DataProvider('xMediaProvider')]
    public function test_x_extraction_maps_to_safe_public_media_response(
        string $mediaType,
        array $assets,
    ): void {
        Http::fake(function (Request $request) use ($mediaType, $assets) {
            return Http::response(
                $this->xSuccessResponse($request->data()['request_id'], $mediaType, $assets),
                200,
            );
        });

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://twitter.com/example/status/123?ref=share',
        ])
            ->assertOk()
            ->assertJsonPath('data.platform', 'x')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.media_type', $mediaType)
            ->assertJsonPath('data.url', 'https://x.com/example/status/123')
            ->assertJsonCount(count($assets), 'data.assets')
            ->assertJsonCount(count($assets) + (count($assets) > 1 ? 1 : 0), 'data.outputs');

        foreach ($response->json('data.outputs') as $output) {
            $this->assertTrue($output['available']);
            $this->assertContains($output['delivery'], ['proxy', 'job']);
        }
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
        $this->assertStringNotContainsString('twimg.com', $response->getContent());
        $this->assertStringNotContainsString('traceback', strtolower($response->getContent()));
    }

    public function test_malformed_x_asset_host_is_rejected_as_invalid_contract(): void
    {
        Http::fake(function (Request $request) {
            $assets = [self::videoAsset()];
            $assets[0]['url'] = 'https://video.twimg.com.evil.example/video.mp4';

            return Http::response(
                $this->xSuccessResponse($request->data()['request_id'], 'video', $assets),
            );
        });

        $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_invalid_response');
    }

    public function test_x_variant_outputs_keep_their_own_detail_metadata(): void
    {
        Http::fake(function (Request $request) {
            $asset = self::videoAsset();
            $asset['variants'] = [
                [...$asset['variants'][0], 'quality_label' => '720×900', 'width' => 720, 'height' => 900],
                [...$asset['variants'][0], 'quality_label' => '480×600', 'width' => 480, 'height' => 600, 'is_preferred' => false],
                [...$asset['variants'][0], 'quality_label' => '320×400', 'width' => 320, 'height' => 400, 'is_preferred' => false],
            ];

            return Http::response($this->xSuccessResponse($request->data()['request_id'], 'video', [$asset]));
        });

        $response = $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertOk();

        $this->assertSame('720×900 · secure proxy delivery', $response->json('data.outputs.0.detail'));
        $this->assertSame('480×600 · secure proxy delivery', $response->json('data.outputs.1.detail'));
        $this->assertSame('320×400 · secure proxy delivery', $response->json('data.outputs.2.detail'));
    }

    public function test_no_media_maps_to_a_readable_validation_error(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'error' => [
                    'code' => 'no_media',
                    'message' => 'Internal provider wording.',
                    'request_id' => $request->data()['request_id'],
                    'details' => ['provider' => 'x', 'status' => 'no_media'],
                ],
            ], 422);
        });

        $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.url.0',
                'This public X post does not contain directly attached media.',
            );
    }

    public function test_upstream_rate_limit_does_not_expose_python_details(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'error' => [
                    'code' => 'rate_limited_upstream',
                    'message' => 'Sensitive X response detail.',
                    'request_id' => $request->data()['request_id'],
                    'details' => [],
                ],
            ], 503);
        });

        $response = $this->postJson(
            '/api/analyze',
            ['url' => 'https://x.com/example/status/123'],
        )->assertServiceUnavailable();

        $this->assertStringNotContainsString('Sensitive X response detail', $response->getContent());
        $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
    }

    public function test_laravel_calls_extractor_and_maps_recognition_to_public_preview(): void
    {
        Http::fake(function (Request $request) {
            $requestId = $request->data()['request_id'];

            return Http::response($this->stubResponse(
                $requestId,
                'x',
                'https://x.com/example/status/123',
            ), 501);
        });

        $this->postJson('/api/analyze', [
            'url' => 'https://x.com/example/status/123#fragment',
        ])
            ->assertOk()
            ->assertJsonPath('data.platform', 'x')
            ->assertJsonPath('data.url', 'https://x.com/example/status/123')
            ->assertJsonPath('data.status', 'preview');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://extractor:8000/v1/extract'
                && $request->method() === 'POST'
                && $request->data()['options'] === ['metadata_only' => true]
                && is_string($request->data()['request_id']);
        });
    }

    public function test_connection_failure_maps_to_safe_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://x.com/example/status/123',
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'upstream_unavailable')
            ->assertJsonPath('error.message', 'The analysis service is temporarily unavailable.');

        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
        $this->assertStringNotContainsString('connection refused', $response->getContent());
        $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
    }

    public function test_timeout_maps_to_safe_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: operation timed out'));

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://x.com/example/status/123',
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'upstream_unavailable');

        $this->assertStringNotContainsString('cURL error 28', $response->getContent());
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
    }

    public function test_invalid_extractor_json_maps_to_safe_502(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response('not-json', 501, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_invalid_response');
    }

    public function test_extractor_validation_error_maps_to_public_422(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'unsupported_host',
                    'message' => 'Internal wording is not forwarded.',
                    'request_id' => 'contract-error',
                    'details' => [],
                ],
            ], 422),
        ]);

        $this->postJson('/api/analyze', ['url' => 'https://example.com/post'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'This media host is not supported yet.');
    }

    public function test_extractor_server_error_maps_to_safe_503(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Sensitive Python detail',
                    'request_id' => 'python-error',
                    'details' => [],
                ],
            ], 500),
        ]);

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://x.com/example/status/123',
        ])->assertServiceUnavailable();

        $this->assertStringNotContainsString('Sensitive Python detail', $response->getContent());
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
    }

    public function test_mismatched_request_id_is_rejected(): void
    {
        Http::fake([
            'http://extractor:8000/*' => Http::response(
                $this->stubResponse(
                    'wrong-request-id',
                    'instagram',
                    'https://instagram.com/p/example/',
                ),
                501,
            ),
        ]);

        $this->postJson('/api/analyze', [
            'url' => 'https://instagram.com/p/example/',
        ])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_invalid_response');
    }

    public function test_instagram_carousel_maps_to_safe_public_response(): void
    {
        Http::fake(function (Request $request) {
            $requestId = $request->data()['request_id'];
            $assets = [
                self::instagramAsset('image', 1),
                self::instagramAsset('video', 2),
            ];

            return Http::response([
                'data' => [
                    'request_id' => $requestId,
                    'provider' => 'instagram',
                    'provider_label' => 'Instagram',
                    'provider_variant' => 'post',
                    'media_type' => 'carousel',
                    'source_url' => 'https://instagram.com/p/Code123/?utm_source=share',
                    'normalized_url' => 'https://www.instagram.com/p/Code123/',
                    'status' => 'ready',
                    'provider_maturity' => null,
                    'warnings' => [],
                    'metadata' => [
                        'post_id' => 'Code123',
                        'caption' => 'Public Instagram caption',
                        'author_name' => 'Example Author',
                        'author_handle' => 'example.author',
                        'published_at' => '2026-07-28T12:00:00Z',
                        'thumbnail_url' => $assets[0]['thumbnail_url'],
                        'media_count' => 2,
                    ],
                    'assets' => $assets,
                    'capabilities' => ['metadata', 'media_assets', 'multiple_assets'],
                ],
            ]);
        });

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://instagram.com/p/Code123/?utm_source=share',
        ])->assertOk()
            ->assertJsonPath('data.platform', 'instagram')
            ->assertJsonPath('data.platform_label', 'Instagram')
            ->assertJsonPath('data.media_type', 'carousel')
            ->assertJsonPath('data.url', 'https://www.instagram.com/p/Code123/')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonCount(2, 'data.assets');

        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
        foreach ($response->json('data.outputs') as $output) {
            $this->assertTrue($output['available']);
        }
        $this->assertSame('Image 1 of 2', $response->json('data.outputs.0.label'));
        $this->assertSame('1080×1350 · secure proxy delivery', $response->json('data.outputs.0.detail'));
        $this->assertSame('asset-1', $response->json('data.outputs.0.asset_id'));
        $this->assertSame('Video 2 of 2', $response->json('data.outputs.1.label'));
        $this->assertSame('1080×1350 · secure proxy delivery', $response->json('data.outputs.1.detail'));
        $this->assertSame('asset-2', $response->json('data.outputs.1.asset_id'));
        $this->assertStringNotContainsString('cdninstagram.com', $response->getContent());
    }

    public function test_linkedin_extraction_maps_to_safe_public_response(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(
                $this->linkedinSuccessResponse($request->data()['request_id']),
            );
        });

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://linkedin.com/posts/example-activity-1234567890123456789-abcd?trk=share',
        ])->assertOk()
            ->assertJsonPath('data.platform', 'linkedin')
            ->assertJsonPath('data.platform_label', 'LinkedIn')
            ->assertJsonPath('data.provider_maturity', 'stable')
            ->assertJsonPath('data.media_type', 'carousel')
            ->assertJsonPath(
                'data.url',
                'https://www.linkedin.com/feed/update/urn:li:activity:1234567890123456789/',
            )
            ->assertJsonCount(2, 'data.assets')
            ->assertJsonCount(3, 'data.outputs');

        $this->assertSame(
            "Public availability depends on LinkedIn's current unauthenticated response.",
            $response->json('data.warnings.0'),
        );
        $this->assertSame('Example Organization', $response->json('data.metadata.author_name'));
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
        $this->assertStringNotContainsString('licdn.com', $response->getContent());

        foreach ($response->json('data.outputs') as $output) {
            $this->assertTrue($output['available']);
            $this->assertContains($output['delivery'], ['proxy', 'job']);
        }
    }

    public function test_linkedin_extraction_accepts_a_long_percent_encoded_canonical_post_url(): void
    {
        $canonicalUrl = 'https://www.linkedin.com/posts/istanbul-sensorler_panasonicindustry-hgt1010-%C3%B6l%C3%A7%C3%BCmsens%C3%B6r%C3%BC-activity-7487771630123769856-Te6v/';

        Http::fake(function (Request $request) use ($canonicalUrl) {
            $payload = $this->linkedinSuccessResponse($request->data()['request_id']);
            $payload['data']['provider_variant'] = 'post';
            $payload['data']['normalized_url'] = $canonicalUrl;

            return Http::response($payload);
        });

        $this->postJson('/api/analyze', [
            'url' => $canonicalUrl,
        ])->assertOk()
            ->assertJsonPath('data.platform', 'linkedin')
            ->assertJsonPath('data.provider_maturity', 'stable')
            ->assertJsonPath('data.url', $canonicalUrl);
    }

    public function test_linkedin_text_only_post_is_ready_without_media_assets(): void
    {
        Http::fake(function (Request $request) {
            $payload = $this->linkedinSuccessResponse($request->data()['request_id']);
            $payload['data']['media_type'] = 'text';
            $payload['data']['metadata']['media_count'] = 0;
            $payload['data']['metadata']['thumbnail_url'] = null;
            $payload['data']['assets'] = [];
            $payload['data']['capabilities'] = ['metadata'];

            return Http::response($payload);
        });

        $this->postJson('/api/analyze', [
            'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1234567890123456789/',
        ])->assertOk()
            ->assertJsonPath('data.media_type', 'text')
            ->assertJsonPath('data.thumbnail_url', null)
            ->assertJsonCount(0, 'data.assets')
            ->assertJsonPath('data.outputs.0.available', false);
    }

    #[DataProvider('linkedinErrorProvider')]
    public function test_linkedin_errors_are_translated_without_internal_details(
        string $code,
        int $status,
        string $expectedMessage,
    ): void {
        Http::fake(function (Request $request) use ($code, $status) {
            return Http::response([
                'error' => [
                    'code' => $code,
                    'message' => 'Sensitive provider response and internal path /srv/app.',
                    'request_id' => $request->data()['request_id'],
                    'details' => ['provider' => 'linkedin'],
                ],
            ], $status);
        });

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1234567890123456789/',
        ])->assertStatus($status)
            ->assertJsonPath($status === 422 ? 'errors.url.0' : 'error.message', $expectedMessage);

        $this->assertStringNotContainsString('Sensitive provider', $response->getContent());
        $this->assertStringNotContainsString('/srv/app', $response->getContent());
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function linkedinErrorProvider(): array
    {
        return [
            'authentication required' => [
                'authentication_required',
                422,
                'This LinkedIn post requires signing in and cannot be analyzed anonymously.',
            ],
            'private content' => [
                'private_content',
                422,
                'This LinkedIn post is private or unavailable.',
            ],
            'no media' => [
                'no_media',
                422,
                'No downloadable media metadata was found in this public LinkedIn post.',
            ],
            'upstream blocked' => [
                'upstream_blocked',
                503,
                'LinkedIn temporarily blocked anonymous metadata access. Please try again later.',
            ],
        ];
    }

    public function test_youtube_extraction_maps_formats_and_conversion_plan(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(
                $this->youtubeSuccessResponse($request->data()['request_id']),
            );
        });

        $response = $this->postJson('/api/analyze', [
            'url' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ&list=PLignored',
        ])->assertOk()
            ->assertJsonPath('data.platform', 'youtube')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.metadata.video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.metadata.channel', 'Example Channel')
            ->assertJsonPath('data.video_formats.0.video_codec_family', 'h264')
            ->assertJsonPath('data.video_formats.0.requires_merge', true)
            ->assertJsonPath('data.audio_formats.0.container', 'm4a')
            ->assertJsonPath('data.conversion_plans.0.requires_ffmpeg', true)
            ->assertJsonPath('data.conversion_plans.0.available', true);

        foreach ($response->json('data.outputs') as $output) {
            $this->assertTrue($output['available']);
            $this->assertContains($output['delivery'], ['proxy', 'job']);
        }
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
    }

    public function test_invalid_youtube_format_contract_maps_to_safe_502(): void
    {
        Http::fake(function (Request $request) {
            $payload = $this->youtubeSuccessResponse($request->data()['request_id']);
            $payload['data']['metadata']['video_formats'][0]['format_id'] = '../unsafe';

            return Http::response($payload);
        });

        $this->postJson('/api/analyze', [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_invalid_response');
    }

    public function test_landing_and_health_do_not_depend_on_extractor_availability(): void
    {
        Http::fake(fn () => throw new ConnectionException('unavailable'));

        $this->get('/')->assertOk()->assertSee('Save It');
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'application' => 'Save It']);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function stubResponse(string $requestId, string $provider, string $url): array
    {
        return [
            'error' => [
                'code' => 'provider_not_implemented',
                'message' => 'The provider is recognized, but extraction is not implemented yet.',
                'request_id' => $requestId,
                'details' => [
                    'provider' => $provider,
                    'provider_label' => ucfirst($provider),
                    'provider_variant' => null,
                    'media_type' => 'unknown',
                    'normalized_url' => $url,
                    'status' => 'not_implemented',
                    'metadata' => null,
                    'assets' => [],
                    'capabilities' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function xSuccessResponse(string $requestId, string $mediaType, array $assets): array
    {
        return [
            'data' => [
                'request_id' => $requestId,
                'provider' => 'x',
                'provider_label' => 'X',
                'provider_variant' => null,
                'media_type' => $mediaType,
                'source_url' => 'https://twitter.com/example/status/123?ref=share',
                'normalized_url' => 'https://x.com/example/status/123',
                'status' => 'ready',
                'provider_maturity' => null,
                'warnings' => [],
                'metadata' => [
                    'post_id' => '123',
                    'text' => 'A real public post title',
                    'author_name' => 'Example',
                    'author_handle' => 'example',
                    'published_at' => '2026-07-28T12:00:00Z',
                    'thumbnail_url' => $assets[0]['thumbnail_url'],
                    'media_count' => count($assets),
                ],
                'assets' => $assets,
                'capabilities' => ['metadata', 'media_assets', 'video_variants'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeSuccessResponse(string $requestId): array
    {
        return [
            'data' => [
                'request_id' => $requestId,
                'provider' => 'youtube',
                'provider_label' => 'YouTube',
                'provider_variant' => 'video',
                'media_type' => 'video',
                'source_url' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ&list=PLignored',
                'normalized_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'status' => 'ready',
                'provider_maturity' => null,
                'warnings' => [],
                'metadata' => [
                    'video_id' => 'dQw4w9WgXcQ',
                    'title' => 'Public YouTube video',
                    'author_name' => 'Example Channel',
                    'author_handle' => 'UCexample',
                    'duration_ms' => 212000,
                    'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg',
                    'thumbnails' => [[
                        'url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg',
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

    /**
     * @return array<string, mixed>
     */
    private function linkedinSuccessResponse(string $requestId): array
    {
        $assets = [
            self::linkedinAsset('image', 1),
            self::linkedinAsset('video', 2),
        ];

        return [
            'data' => [
                'request_id' => $requestId,
                'provider' => 'linkedin',
                'provider_label' => 'LinkedIn',
                'provider_variant' => 'activity',
                'media_type' => 'carousel',
                'source_url' => 'https://linkedin.com/posts/example-activity-1234567890123456789-abcd?trk=share',
                'normalized_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1234567890123456789/',
                'status' => 'ready',
                'provider_maturity' => 'stable',
                'warnings' => [
                    "Public availability depends on LinkedIn's current unauthenticated response.",
                    'Posts that require signing in cannot be analyzed.',
                ],
                'metadata' => [
                    'post_id' => '1234567890123456789',
                    'title' => 'Public LinkedIn post',
                    'description' => 'A public post preview.',
                    'author_name' => 'Example Organization',
                    'author_handle' => null,
                    'published_at' => '2026-07-29T12:00:00Z',
                    'thumbnail_url' => $assets[0]['thumbnail_url'],
                    'captions_url' => 'https://dms.licdn.com/captions/private-token',
                    'media_count' => count($assets),
                ],
                'assets' => $assets,
                'capabilities' => ['metadata', 'media_assets', 'multiple_assets'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageAsset(int $order = 1): array
    {
        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => 'image',
            'role' => $order === 1 ? 'primary' : 'gallery',
            'url' => "https://pbs.twimg.com/media/image-{$order}.jpg?name=orig",
            'thumbnail_url' => "https://pbs.twimg.com/media/image-{$order}.jpg",
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 800,
            'duration_ms' => null,
            'alt_text' => null,
            'variants' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function videoAsset(string $type = 'video', int $order = 1): array
    {
        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => $type,
            'role' => $order === 1 ? 'primary' : 'gallery',
            'url' => 'https://video.twimg.com/path/1280x720/video.mp4',
            'thumbnail_url' => 'https://pbs.twimg.com/media/poster.jpg',
            'mime_type' => 'video/mp4',
            'width' => 1280,
            'height' => 720,
            'duration_ms' => 12000,
            'alt_text' => null,
            'variants' => [[
                'url' => 'https://video.twimg.com/path/1280x720/video.mp4',
                'mime_type' => 'video/mp4',
                'protocol' => 'https',
                'bitrate' => 2176000,
                'width' => 1280,
                'height' => 720,
                'quality_label' => '1280×720',
                'is_preferred' => true,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function instagramAsset(string $type, int $order): array
    {
        $base = "https://scontent-lhr8-1.cdninstagram.com/v/media-{$order}";
        $url = $type === 'video' ? "{$base}.mp4" : "{$base}.jpg";

        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => $type,
            'role' => $order === 1 ? 'primary' : 'gallery',
            'url' => $url,
            'thumbnail_url' => "{$base}.jpg",
            'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'width' => 1080,
            'height' => 1350,
            'duration_ms' => $type === 'video' ? 12000 : null,
            'alt_text' => null,
            'variants' => $type === 'video' ? [[
                'url' => $url,
                'mime_type' => 'video/mp4',
                'protocol' => 'https',
                'bitrate' => null,
                'width' => 1080,
                'height' => 1350,
                'quality_label' => '1080×1350',
                'is_preferred' => true,
            ]] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkedinAsset(string $type, int $order): array
    {
        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => $type,
            'role' => $order === 1 ? 'primary' : 'gallery',
            'url' => "https://media.licdn.com/dms/{$type}/asset-{$order}",
            'thumbnail_url' => "https://media.licdn.com/dms/image/preview-{$order}",
            'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'width' => 1200,
            'height' => 627,
            'duration_ms' => $type === 'video' ? 12000 : null,
            'alt_text' => null,
            'variants' => [],
        ];
    }
}
