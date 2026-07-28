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
            ->assertJsonCount(count($assets), 'data.outputs');

        foreach ($response->json('data.outputs') as $output) {
            $this->assertFalse($output['available']);
            $this->assertArrayNotHasKey('download_url', $output);
        }
        $this->assertStringNotContainsString('extractor:8000', $response->getContent());
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
}
