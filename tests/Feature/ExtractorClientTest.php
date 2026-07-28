<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractorClientTest extends TestCase
{
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
}
