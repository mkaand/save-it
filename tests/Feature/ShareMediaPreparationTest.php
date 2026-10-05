<?php

namespace Tests\Feature;

use App\Jobs\PrepareShareMediaJob;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\ShareMediaPreparationJobStore;
use App\Services\Downloads\ShareMediaPreparationService;
use App\Services\Downloads\ShareMediaPreparationStore;
use App\Services\Downloads\UpstreamUrlPolicy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShareMediaPreparationTest extends TestCase
{
    /** @var array<int, string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            File::delete($path);
        }
        parent::tearDown();
    }

    public function test_share_limit_is_one_hundred_mib(): void
    {
        $this->assertSame(104_857_600, config('services.downloads.share_max_file_bytes'));
    }

    public function test_share_preparation_strips_stream_creation_dates_without_changing_bitstreams_or_normal_download_source(): void
    {
        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('FFmpeg integration coverage runs in the Docker validation image.');
        }

        $source = storage_path('app/private/downloads/ios-share-source-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        $this->createMp4Fixture($source);
        $this->paths[] = $source;
        $sourceHash = hash_file('sha256', $source);
        $sourceVideoHash = $this->streamHash($source, '0:v:0');
        $sourceAudioHash = $this->streamHash($source, '0:a:0');
        $this->assertSame('2025-12-28T21:03:51.000000Z', $this->probe($source)['streams'][0]['tags']['creation_time']);

        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file',
            'provider' => 'facebook',
            'path' => $source,
            'filename' => 'Rick Astley.mp4',
            'mime_type' => 'video/mp4',
        ]);

        $result = $this->app->make(ShareMediaPreparationService::class)->prepare($token);
        $url = route('api.share-preparations.show', ['preparation' => $result['id']], false);
        $this->assertMatchesRegularExpression('#^/api/share-preparations/[a-z0-9]{48}$#', $url);
        $identifier = basename($url);
        $prepared = $this->app->make(ShareMediaPreparationStore::class)->resolve($identifier);
        $this->assertNotNull($prepared);
        $this->paths[] = $prepared['path'];

        $probe = $this->probe($prepared['path']);
        $this->assertStringContainsString('mp4', $this->formatName($prepared['path']));
        $this->assertArrayNotHasKey('creation_time', $probe['format']['tags'] ?? []);
        foreach ($probe['streams'] as $stream) {
            $this->assertArrayNotHasKey('creation_time', $stream['tags'] ?? []);
        }
        $this->assertSame($sourceVideoHash, $this->streamHash($prepared['path'], '0:v:0'));
        $this->assertSame($sourceAudioHash, $this->streamHash($prepared['path'], '0:a:0'));
        $this->assertSame($sourceHash, hash_file('sha256', $source));

        $this->withHeader('Range', 'bytes=0-0')->get($url)
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 0-0/'.filesize($prepared['path']))
            ->assertHeader('Content-Length', '1');
    }

    public function test_facebook_vp9_share_preparation_transcodes_only_the_prepared_copy_to_ios_compatible_h264(): void
    {
        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('FFmpeg integration coverage runs in the Docker validation image.');
        }
        $source = storage_path('app/private/downloads/facebook-vp9-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        $this->command([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=64x64:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=1000:sample_rate=44100',
            '-t', '1', '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'libvpx-vp9', '-c:a', 'aac',
            '-movflags', '+faststart', $source,
        ]);
        $this->paths[] = $source;
        $this->assertSame('vp9', $this->probe($source)['streams'][0]['codec_name']);

        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file', 'provider' => 'facebook', 'path' => $source,
            'filename' => 'Facebook.mp4', 'mime_type' => 'video/mp4',
        ]);
        $states = [];
        $result = $this->app->make(ShareMediaPreparationService::class)->prepare(
            $token,
            static function (string $stage, ?int $progress) use (&$states): void {
                $states[] = [$stage, $progress];
            },
        );
        $prepared = $this->app->make(ShareMediaPreparationStore::class)->resolve($result['id']);
        $this->assertNotNull($prepared);
        $this->paths[] = $prepared['path'];
        $probe = $this->probe($prepared['path']);
        $this->assertSame('h264', $probe['streams'][0]['codec_name']);
        $this->assertSame('yuv420p', $probe['streams'][0]['pix_fmt']);
        $this->assertSame('audio', $probe['streams'][1]['codec_type']);
        $this->assertSame('vp9', $this->probe($source)['streams'][0]['codec_name']);
        $this->assertContains(['Converting', 0], $states);
        $this->assertContains(['Finalizing', null], $states);
    }

    public function test_share_preparation_is_bounded_and_expired_files_are_cleaned_up(): void
    {
        config(['services.downloads.share_max_file_bytes' => 1]);
        $source = storage_path('app/private/downloads/ios-share-too-large-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        file_put_contents($source, 'too-large');
        $this->paths[] = $source;
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file', 'path' => $source, 'filename' => 'source.mp4', 'mime_type' => 'video/mp4',
        ]);
        try {
            $this->app->make(ShareMediaPreparationService::class)->prepare($token);
            $this->fail('Expected preparation to fail.');
        } catch (DownloadException $exception) {
            $this->assertSame('share_unavailable', $exception->publicCode);
        }

        $store = $this->app->make(ShareMediaPreparationStore::class);
        $directory = $store->directory();
        File::ensureDirectoryExists($directory, 0700, true);
        $orphan = $directory.'/'.str_repeat('a', 48).'.mp4';
        file_put_contents($orphan, 'orphan');
        touch($orphan, time() - 700);
        $store->cleanupExpired();
        $this->assertFileDoesNotExist($orphan);
    }

    public function test_tiktok_proxy_share_preparation_uses_the_internal_media_relay(): void
    {
        if (! is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('FFmpeg integration coverage runs in the Docker validation image.');
        }

        $page = 'https://www.tiktok.com/@creator/video/1234567890123456789';
        $media = 'https://v16-webapp-prime.tiktok.com/obj/example.mp4?token=sensitive';
        $source = storage_path('app/private/downloads/tiktok-share-source-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        $this->createMp4Fixture($source);
        $this->paths[] = $source;
        $bytes = file_get_contents($source);
        $this->assertIsString($bytes);
        $size = strlen($bytes);
        $this->app->bind(UpstreamUrlPolicy::class, fn () => new class extends UpstreamUrlPolicy
        {
            protected function resolveAddresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
        Http::fake(function ($request) use ($page, $media, $bytes, $size) {
            $this->assertSame('http://extractor:8000/v1/tiktok/media', $request->url());
            $this->assertSame($page, $request->data()['source_page_url'] ?? null);
            $this->assertSame($media, $request->data()['media_url'] ?? null);
            $this->assertSame('bytes=0-'.($size - 1), $request->header('Range')[0] ?? null);

            return Http::response($bytes, 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) $size,
                'Content-Range' => 'bytes 0-'.($size - 1)."/{$size}",
                'Accept-Ranges' => 'bytes',
            ]);
        });
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'version' => 1,
            'mode' => 'proxy',
            'provider' => 'tiktok',
            'source_page_url' => $page,
            'upstream_url' => $media,
            'filename' => 'TikTok.mp4',
            'mime_type' => 'video/mp4',
            'expected_size' => $size,
        ]);

        $result = $this->app->make(ShareMediaPreparationService::class)->prepare($token);
        $prepared = $this->app->make(ShareMediaPreparationStore::class)->resolve($result['id']);
        $this->assertNotNull($prepared);
        $this->paths[] = $prepared['path'];
        Http::assertSentCount(1);
    }

    public function test_remux_output_over_the_share_limit_is_rejected_without_caching_a_partial_file(): void
    {
        $source = storage_path('app/private/downloads/ios-share-source-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        file_put_contents($source, str_repeat('s', 16));
        $this->paths[] = $source;
        $script = $this->fakeFfmpeg('printf \'0123456789abcdefg\' > "$last"'."\nexit 0");
        config([
            'services.downloads.share_max_file_bytes' => 16,
            'services.downloads.share_ffmpeg_binary' => $script,
        ]);
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file', 'path' => $source, 'filename' => 'source.mp4', 'mime_type' => 'video/mp4',
        ]);

        try {
            $this->app->make(ShareMediaPreparationService::class)->prepare($token);
            $this->fail('Expected preparation to fail.');
        } catch (DownloadException $exception) {
            $this->assertSame('share_unavailable', $exception->publicCode);
        }
        $this->assertSame([], glob($this->app->make(ShareMediaPreparationStore::class)->directory().'/*.mp4') ?: []);
    }

    public function test_failed_remux_never_serves_its_partial_output(): void
    {
        $source = storage_path('app/private/downloads/ios-share-source-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        file_put_contents($source, 'source');
        $this->paths[] = $source;
        $script = $this->fakeFfmpeg('printf \'partial\' > "$last"'."\nexit 1");
        config(['services.downloads.share_ffmpeg_binary' => $script]);
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file', 'path' => $source, 'filename' => 'source.mp4', 'mime_type' => 'video/mp4',
        ]);

        try {
            $this->app->make(ShareMediaPreparationService::class)->prepare($token);
            $this->fail('Expected preparation to fail.');
        } catch (DownloadException $exception) {
            $this->assertSame('share_unavailable', $exception->publicCode);
        }
        $this->assertSame([], glob($this->app->make(ShareMediaPreparationStore::class)->directory().'/*.mp4') ?: []);
    }

    public function test_share_preparation_is_queued_and_status_never_exposes_its_internal_asset(): void
    {
        Bus::fake();
        $source = storage_path('app/private/downloads/share-job-'.bin2hex(random_bytes(4)).'.mp4');
        File::ensureDirectoryExists(dirname($source), 0700, true);
        file_put_contents($source, 'source');
        $this->paths[] = $source;
        $token = $this->app->make(DownloadAssetStore::class)->issue([
            'mode' => 'local_file', 'provider' => 'facebook', 'path' => $source,
            'filename' => 'source.mp4', 'mime_type' => 'video/mp4', 'upstream_url' => 'https://secret.example/video',
        ]);

        $response = $this->postJson('/api/share-preparations', ['token' => $token])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress', null);
        $statusUrl = $response->json('data.status_url');
        $this->assertMatchesRegularExpression('#^/api/share-preparation-jobs/[a-z0-9]{48}$#', $statusUrl);
        Bus::assertDispatched(PrepareShareMediaJob::class);

        $this->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertDontSee('secret.example')
            ->assertDontSee($source);
    }

    public function test_share_preparation_job_state_returns_ready_fields_only_after_completion(): void
    {
        $store = $this->app->make(ShareMediaPreparationJobStore::class);
        $id = $store->create(['mode' => 'local_file', 'path' => '/private/source.mp4']);
        $store->update($id, [
            'status' => 'ready', 'stage' => 'Ready', 'progress' => 100,
            'prepared_url' => '/api/share-preparations/'.str_repeat('a', 48),
            'filename' => 'video.mp4', 'mime_type' => 'video/mp4',
        ]);
        $this->getJson('/api/share-preparation-jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.filename', 'video.mp4')
            ->assertJsonMissingPath('data.asset')
            ->assertDontSee('/private/source.mp4');
    }

    public function test_ffmpeg_progress_is_duration_based_and_never_fabricated(): void
    {
        $this->assertSame(0, ShareMediaPreparationService::conversionProgressFromLine('out_time_us=0', 10.0));
        $this->assertSame(42, ShareMediaPreparationService::conversionProgressFromLine('out_time_ms=4250000', 10.0));
        $this->assertSame(99, ShareMediaPreparationService::conversionProgressFromLine('out_time_us=12000000', 10.0));
        $this->assertNull(ShareMediaPreparationService::conversionProgressFromLine('out_time_us=4250000', null));
        $this->assertNull(ShareMediaPreparationService::conversionProgressFromLine('progress=continue', 10.0));
    }

    public function test_redis_queue_retry_after_outlives_the_media_job_timeout(): void
    {
        $this->assertGreaterThan(
            (int) config('services.downloads.job_timeout_seconds'),
            (int) config('queue.connections.redis.retry_after'),
        );
    }

    private function createMp4Fixture(string $path): void
    {
        $this->command([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=64x64:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=1000:sample_rate=44100',
            '-t', '1', '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
            '-metadata', 'creation_time=2025-12-28T21:03:51Z',
            '-metadata:s:v:0', 'creation_time=2025-12-28T21:03:51Z',
            '-metadata:s:a:0', 'creation_time=2025-12-28T21:03:51Z',
            '-movflags', 'use_metadata_tags+faststart', $path,
        ]);
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        return json_decode($this->command([
            '/usr/bin/ffprobe', '-v', 'error', '-show_format', '-show_streams', '-of', 'json', $path,
        ]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function formatName(string $path): string
    {
        return trim($this->command([
            '/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=format_name', '-of', 'default=nw=1:nk=1', $path,
        ]));
    }

    private function streamHash(string $path, string $map): string
    {
        return trim($this->command([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $path,
            '-map', $map, '-c', 'copy', '-f', 'hash', '-',
        ]));
    }

    private function fakeFfmpeg(string $body): string
    {
        $script = storage_path('app/private/share-ffmpeg-'.bin2hex(random_bytes(4)).'.sh');
        file_put_contents($script, "#!/bin/sh\nlast=\nfor argument do last=\"\$argument\"; done\ncase \" \$* \" in *' -fs '*) exit 99;; esac\n{$body}\n");
        chmod($script, 0700);
        $this->paths[] = $script;

        return $script;
    }

    /** @param array<int, string> $command */
    private function command(array $command): string
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $spec, $pipes, null, [], ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }
}
