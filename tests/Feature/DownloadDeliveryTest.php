<?php

namespace Tests\Feature;

use App\Jobs\PrepareDownloadJob;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadPipeline;
use App\Services\Downloads\UpstreamFileDownloader;
use App\Services\Downloads\UpstreamUrlPolicy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class DownloadDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.downloads.token_ttl_seconds' => 600,
            'services.downloads.max_file_bytes' => 1024,
            'services.downloads.max_zip_assets' => 10,
            'services.downloads.max_zip_bytes' => 4096,
        ]);
        $this->app->bind(UpstreamUrlPolicy::class, fn () => new class extends UpstreamUrlPolicy
        {
            protected function resolveAddresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    public function test_signed_download_token_rejects_tampering_and_expiry(): void
    {
        $store = $this->app->make(DownloadAssetStore::class);
        $token = $store->issue($this->asset());

        $this->assertSame('x', $store->resolve($token)['provider']);

        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->getJson("/api/downloads/{$tampered}")
            ->assertGone()
            ->assertJsonPath('error.code', 'download_token_expired');

        Cache::flush();
        $this->getJson("/api/downloads/{$token}")
            ->assertGone()
            ->assertJsonPath('error.code', 'download_token_expired');
    }

    public function test_proxy_forwards_one_range_and_preserves_partial_response(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('bytes=0-0', $request->header('Range')[0] ?? null);

            return Http::response('x', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/100',
                'Accept-Ranges' => 'bytes',
            ]);
        });
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $response = $this->withHeader('Range', 'bytes=0-0')->get("/api/downloads/{$token}");

        $response->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 0-0/100')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition');
        $this->assertSame('x', $response->streamedContent());
        Http::assertSentCount(1);
    }

    public function test_unknown_size_range_skips_probe_and_uses_the_client_range_response(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('bytes=0-0', $request->header('Range')[0] ?? null);

            return Http::response('x', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/100',
            ]);
        });
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'expected_size' => null,
        ]);

        $response = $this->withHeader('Range', 'bytes=0-0')->get("/api/downloads/{$token}");

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-0/100')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('x', $response->streamedContent());
        Http::assertSentCount(1);
    }

    public function test_unknown_size_arbitrary_single_range_is_validated_without_a_probe(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('bytes=10-19', $request->header('Range')[0] ?? null);

            return Http::response('0123456789', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '10',
                'Content-Range' => 'bytes 10-19/100',
            ]);
        });
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'expected_size' => null,
        ]);

        $response = $this->withHeader('Range', 'bytes=10-19')->get("/api/downloads/{$token}");

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 10-19/100')
            ->assertHeader('Accept-Ranges', 'bytes');
        Http::assertSentCount(1);
    }

    public function test_valid_partial_response_synthesizes_accept_ranges_when_upstream_omits_it(): void
    {
        Http::fake([
            '*' => Http::response('x', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/100',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $this->withHeader('Range', 'bytes=0-0')
            ->get("/api/downloads/{$token}")
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-0/100')
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_invalid_partial_content_range_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response('x', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/100;unsafe',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $this->withHeader('Range', 'bytes=0-0')
            ->getJson("/api/downloads/{$token}")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'invalid_upstream_range')
            ->assertHeaderMissing('Accept-Ranges');
    }

    public function test_partial_content_length_must_match_content_range(): void
    {
        Http::fake([
            '*' => Http::response('xx', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '2',
                'Content-Range' => 'bytes 0-0/100',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $this->withHeader('Range', 'bytes=0-0')
            ->getJson("/api/downloads/{$token}")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'invalid_upstream_range');
    }

    public function test_full_response_does_not_advertise_unvalidated_range_support(): void
    {
        Http::fake([
            '*' => Http::response('complete', 200, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '8',
                'Accept-Ranges' => 'bytes',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'expected_size' => 8,
        ]);

        $response = $this->get("/api/downloads/{$token}");

        $response->assertOk()
            ->assertHeaderMissing('Accept-Ranges')
            ->assertHeaderMissing('Content-Range');
        $this->assertSame('complete', $response->streamedContent());
    }

    public function test_multiple_ranges_are_rejected_without_upstream_request(): void
    {
        Http::fake();
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $this->withHeader('Range', 'bytes=0-1,4-5')
            ->getJson("/api/downloads/{$token}")
            ->assertStatus(416)
            ->assertJsonPath('error.code', 'range_not_satisfiable');
        Http::assertNothingSent();
    }

    public function test_upstream_416_preserves_safe_unsatisfied_content_range(): void
    {
        Http::fake([
            '*' => Http::response('', 416, ['Content-Range' => 'bytes */100']),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $this->withHeader('Range', 'bytes=200-300')
            ->getJson("/api/downloads/{$token}")
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */100')
            ->assertJsonPath('error.code', 'range_not_satisfiable');
    }

    public function test_range_total_size_is_enforced(): void
    {
        Http::fake([
            '*' => Http::response('x', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/2048',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'expected_size' => null,
        ]);

        $this->withHeader('Range', 'bytes=0-0')
            ->getJson("/api/downloads/{$token}")
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'media_too_large');
        Http::assertSentCount(1);
    }

    public function test_proxy_uses_a_bounded_range_probe_when_an_asset_omits_content_length(): void
    {
        Http::fakeSequence()
            ->push('x', 206, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '1',
                'Content-Range' => 'bytes 0-0/8',
            ])
            ->push('complete', 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'mime_type' => 'image/jpeg',
            'filename' => 'preview.jpg',
            'expected_size' => null,
        ]);

        $response = $this->get("/api/downloads/{$token}");

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('complete', $response->streamedContent());
        Http::assertSentCount(2);
        $this->assertSame(
            [['bytes=0-0'], []],
            Http::recorded()->map(
                static fn (array $pair): array => $pair[0]->header('Range'),
            )->all(),
        );
    }

    public function test_known_size_full_download_does_not_probe(): void
    {
        Http::fake(function ($request) {
            $this->assertSame([], $request->header('Range'));

            return Http::response('complete', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '8',
            ]);
        });
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            ...$this->asset(),
            'mime_type' => 'image/jpeg',
            'filename' => 'preview.jpg',
            'expected_size' => 8,
        ]);

        $response = $this->get("/api/downloads/{$token}");

        $response->assertOk();
        $this->assertSame('complete', $response->streamedContent());
        Http::assertSentCount(1);
    }

    public function test_job_endpoint_dispatches_only_a_signed_supported_plan(): void
    {
        Bus::fake();
        $store = $this->app->make(DownloadAssetStore::class);
        $token = $store->issue([
            'mode' => 'youtube_mp3',
            'filename' => 'audio.mp3',
            'bitrate_kbps' => 192,
            'sources' => [[
                'provider' => 'youtube',
                'normalized_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'format_id' => '140',
            ]],
        ]);

        $response = $this->postJson('/api/download-jobs', ['token' => $token])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.stage', 'Preparing');

        $this->assertStringStartsWith('/api/download-jobs/', $response->json('data.status_url'));
        Bus::assertDispatched(PrepareDownloadJob::class);
        $this->postJson('/api/download-jobs', ['token' => $token])
            ->assertGone()
            ->assertJsonPath('error.code', 'download_token_expired');
    }

    public function test_upstream_errors_and_unsupported_mime_are_sanitized(): void
    {
        Http::fake([
            '*' => Http::response('<script>secret</script>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue($this->asset());

        $response = $this->getJson("/api/downloads/{$token}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unsupported_media_type');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('twimg.com', $response->getContent());
    }

    public function test_zip_pipeline_streams_bounded_assets_with_safe_unique_names(): void
    {
        Http::fakeSequence()
            ->push('first-image', 200, ['Content-Type' => 'image/jpeg'])
            ->push('second-image', 200, ['Content-Type' => 'image/jpeg']);

        $result = $this->app->make(DownloadPipeline::class)->prepare([
            'mode' => 'zip',
            'filename' => 'post-media.zip',
            'sources' => [
                [
                    'provider' => 'x',
                    'upstream_url' => 'https://pbs.twimg.com/media/first.jpg',
                    'filename' => '../same-name.jpg',
                ],
                [
                    'provider' => 'x',
                    'upstream_url' => 'https://pbs.twimg.com/media/second.jpg',
                    'filename' => '../same-name.jpg',
                ],
            ],
        ], static fn (): null => null);

        try {
            Http::assertSent(
                static fn ($request): bool => $request->hasHeader('Range', 'bytes=0-4095'),
            );
            $this->assertSame('application/zip', $result['mime_type']);
            $this->assertGreaterThan(0, $result['size']);

            $archive = new ZipArchive;
            $this->assertTrue($archive->open($result['path']) === true);
            $this->assertSame('01-same-name.jpg', $archive->getNameIndex(0));
            $this->assertSame('02-same-name.jpg', $archive->getNameIndex(1));
            $this->assertSame('first-image', $archive->getFromIndex(0));
            $this->assertSame('second-image', $archive->getFromIndex(1));
            $archive->close();
        } finally {
            File::deleteDirectory(dirname($result['path']));
        }
    }

    public function test_job_downloader_assembles_validated_range_chunks(): void
    {
        Http::fakeSequence()
            ->push('abc', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '3',
                'Content-Range' => 'bytes 0-2/5',
            ])
            ->push('de', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '2',
                'Content-Range' => 'bytes 3-4/5',
            ]);
        $path = storage_path('app/private/range-chunks-'.bin2hex(random_bytes(4)));

        try {
            $written = $this->app->make(UpstreamFileDownloader::class)->download(
                [
                    'provider' => 'x',
                    'upstream_url' => 'https://video.twimg.com/media/video.mp4',
                    'expected_size' => 5,
                ],
                $path,
                16 * 1024 * 1024,
            );
            $this->assertSame(5, $written);
            $this->assertSame('abcde', file_get_contents($path));
            Http::assertSentCount(2);
            $this->assertSame(
                [['bytes=0-4'], ['bytes=3-4']],
                Http::recorded()->map(
                    static fn (array $pair): array => $pair[0]->header('Range'),
                )->all(),
            );
        } finally {
            File::delete($path);
        }
    }

    /** @return array<string, mixed> */
    private function asset(): array
    {
        return [
            'version' => 1,
            'mode' => 'proxy',
            'provider' => 'x',
            'asset_id' => 'asset-1',
            'upstream_url' => 'https://video.twimg.com/media/video.mp4?token=sensitive',
            'mime_type' => 'video/mp4',
            'filename' => "Safe title\r\n.mp4",
            'expected_size' => 100,
        ];
    }
}
