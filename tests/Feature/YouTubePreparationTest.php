<?php

namespace Tests\Feature;

use App\Jobs\PrepareDownloadJob;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\DownloadJobStore;
use App\Services\Downloads\DownloadPipeline;
use App\Services\Downloads\UpstreamFileDownloader;
use App\Services\Downloads\UpstreamUrlPolicy;
use App\Services\Downloads\YouTubeOutputSelector;
use App\Services\Downloads\YouTubePreparationStore;
use App\Services\Runtime\RuntimeArtifactCleanup;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class YouTubePreparationTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.downloads.max_file_bytes' => 32 * 1024 * 1024]);
        $this->fixtures = storage_path('framework/testing/youtube-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->fixtures, 0700, true);
        $this->app->bind(UpstreamUrlPolicy::class, fn () => new class extends UpstreamUrlPolicy
        {
            protected function resolveAddresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtures);
        parent::tearDown();
    }

    public function test_source_resolutions_pair_audio_deterministically_without_duplicate_or_silent_outputs(): void
    {
        $videos = [];
        foreach ([360, 480, 720, 1080, 1440, 2160] as $height) {
            $videos[] = $this->video($height, $height <= 1080 ? 'mp4' : 'webm');
        }
        $progressive = [...$this->video(360), 'format_id' => '18', 'requires_merge' => false, 'has_audio' => true, 'audio_codec' => 'mp4a.40.2'];
        $videos[] = $progressive;
        $videos[] = [...$this->video(1080), 'format_id' => 'duplicate', 'bitrate_kbps' => 1];
        $audio = [$this->audio(), $this->audio('webm')];
        $selected = (new YouTubeOutputSelector)->select($videos, $audio);
        $this->assertSame([2160, 1440, 1080, 720, 480, 360], array_column(array_column($selected, 'video'), 'height'));
        $this->assertSame('18', $selected[5]['video']['format_id']);
        $this->assertNull($selected[5]['audio']);
        $this->assertSame('opus', $selected[0]['audio']['audio_codec']);
        $this->assertSame('mp4a.40.2', $selected[2]['audio']['audio_codec']);
        $this->assertSame([], (new YouTubeOutputSelector)->select([$this->video(2160, 'webm')], [$this->audio()]));
        $this->assertSame([], (new YouTubeOutputSelector)->select([$this->video(1080)], []));
        $original = [...$this->audio(), 'format_id' => 'original', 'language_preference' => 10, 'bitrate_kbps' => 80];
        $this->assertSame('original', (new YouTubeOutputSelector)->select([$this->video(1080)], [$this->audio(), $original])[0]['audio']['format_id']);
        $progressive['height'] = 720;
        $this->assertNull((new YouTubeOutputSelector)->select([$progressive], $audio)[0]['audio']);
    }

    public static function containers(): array
    {
        return [['mp4', 'h264', 'aac'], ['webm', 'vp9', 'opus']];
    }

    #[DataProvider('containers')]
    public function test_real_mux_preserves_streams_and_cleans_sources(string $container, string $videoCodec, string $audioCodec): void
    {
        if (! is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('Actual mux is also exercised inside the application Docker image.');
        }
        $videoPath = $this->fixtures.'/video.'.$container;
        $audioPath = $this->fixtures.'/audio.'.($container === 'mp4' ? 'm4a' : 'webm');
        $this->process(['/usr/bin/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=64x64:d=0.2:r=10', '-an', '-c:v', $container === 'mp4' ? 'libx264' : 'libvpx-vp9', $videoPath]);
        $this->process(['/usr/bin/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.2', '-vn', '-c:a', $container === 'mp4' ? 'aac' : 'libopus', $audioPath]);
        $plan = $this->plan($container);
        $this->fakeSources($plan, file_get_contents($videoPath), file_get_contents($audioPath));
        $states = [];
        $result = app(DownloadPipeline::class)->prepare($plan, function ($stage, $percent) use (&$states): void {
            $states[] = [$stage, $percent];
        });
        try {
            $this->assertSame('video/'.$container, $result['mime_type']);
            $this->assertSame('sample-1080p.'.$container, $result['filename']);
            $this->assertGreaterThan(0, $result['size']);
            $this->assertSame(['output.'.$container], array_map('basename', File::files(dirname($result['path']))));
            $details = json_decode($this->process(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $result['path']]), true);
            $this->assertSame([$videoCodec, $audioCodec], array_column($details['streams'], 'codec_name'));
            $this->assertSame(64, $details['streams'][0]['height']);
            $this->assertGreaterThan(0.1, (float) $details['format']['duration']);
            // Packet hashes prove copy rather than a same-codec re-encode.
            foreach (['v:0' => $videoPath, 'a:0' => $audioPath] as $stream => $original) {
                $args = ['-map', $stream, '-c', 'copy', '-f', 'hash', '-hash', 'sha256', '-'];
                $this->assertSame(
                    $this->process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $original, ...$args]),
                    $this->process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $result['path'], ...$args]),
                );
            }
            $this->assertContains(['Downloading video', 100], $states);
            $this->assertContains(['Downloading audio', 100], $states);
            $this->assertContains(['Merging', null], $states);
            $this->assertSame(['Finalizing', null], end($states));
        } finally {
            File::deleteDirectory(dirname($result['path']));
        }
    }

    public function test_failed_audio_download_and_invalid_mux_leave_no_artifact(): void
    {
        foreach ([true, false] as $audioFails) {
            $plan = $this->plan();
            $this->fakeSources($plan, 'invalid-video', 'invalid-audio', $audioFails);
            try {
                app(DownloadPipeline::class)->prepare($plan, fn () => null);
                $this->fail('Invalid source must not be published.');
            } catch (DownloadException $exception) {
                $this->assertContains($exception->publicCode, ['upstream_unavailable', 'conversion_failed']);
            }
            $this->assertDirectoryDoesNotExist(storage_path('app/private/downloads/'.$plan['_directory']));
        }
    }

    public function test_oversized_plan_and_incompatible_track_are_rejected_before_network(): void
    {
        foreach (['size', 'codec'] as $case) {
            Http::fake();
            $plan = $this->plan();
            if ($case === 'size') {
                $plan['expected_size'] = 32 * 1024 * 1024 + 1;
            } else {
                $plan['sources'][1]['audio_codec'] = 'opus;touch /tmp/unsafe';
            }
            try {
                app(DownloadPipeline::class)->prepare($plan, fn () => null);
                $this->fail('Invalid plan accepted.');
            } catch (DownloadException $exception) {
                $this->assertContains($exception->publicCode, ['media_too_large', 'invalid_download_token']);
            }
            Http::assertNothingSent();
            $this->assertDirectoryDoesNotExist(storage_path('app/private/downloads/'.$plan['_directory']));
        }
    }

    public function test_same_signed_output_reuses_job_and_global_admission_is_bounded(): void
    {
        Bus::fake();
        $tokens = app(DownloadAssetStore::class);
        $token = $tokens->issue($this->plan());
        $first = $this->postJson('/api/download-jobs', ['token' => $token])->assertAccepted();
        $this->postJson('/api/download-jobs', ['token' => $token])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        Bus::assertDispatchedTimes(PrepareDownloadJob::class, 1);
        $this->postJson('/api/download-jobs', ['token' => $tokens->issue($this->plan())])->assertAccepted();
        $this->postJson('/api/download-jobs', ['token' => $tokens->issue($this->plan())])->assertStatus(429)->assertJsonPath('error.code', 'preparation_busy');
        $this->postJson('/api/download-jobs', ['token' => substr($token, 0, -2).'00'])->assertGone();
        $this->travel(11)->minutes();
        $this->postJson('/api/download-jobs', ['token' => $token])->assertGone();
    }

    public function test_timeout_marks_job_failed_and_removes_only_its_directory(): void
    {
        $plan = $this->plan();
        $path = storage_path('app/private/downloads/'.$plan['_directory']);
        File::makeDirectory($path, 0700, true);
        File::put($path.'/output.part', 'partial');
        $id = app(DownloadJobStore::class)->create(['mode' => 'youtube_merge']);
        (new PrepareDownloadJob($id, $plan))->failed(null);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertDirectoryExists($this->fixtures);
        $this->getJson('/api/download-jobs/'.$id)->assertOk()->assertJsonPath('data.status', 'failed');
    }

    public function test_expired_admission_cannot_start_an_abandoned_queued_job(): void
    {
        $lock = Cache::lock('save-it:youtube-mux-slot:0', 3600);
        $this->assertTrue($lock->get());
        $plan = $this->plan();
        $plan['_slot'] = ['name' => 'save-it:youtube-mux-slot:0', 'owner' => $lock->owner()];
        app(YouTubePreparationStore::class)->assertActive($plan);
        $lock->release();
        $this->expectException(DownloadException::class);
        app(YouTubePreparationStore::class)->assertActive($plan);
    }

    public function test_unknown_length_stream_counts_bytes_and_enforces_the_limit(): void
    {
        $asset = ['provider' => 'youtube', 'upstream_url' => 'https://rr1.googlevideo.com/video'];
        Http::fake(fn () => Http::response('123456789', 200, ['Content-Type' => 'video/mp4']));
        $states = [];
        $size = app(UpstreamFileDownloader::class)->download($asset, $this->fixtures.'/unknown', 10,
            function ($bytes, $total) use (&$states): void {
                $states[] = [$bytes, $total];
            });
        $this->assertSame(9, $size);
        $this->assertSame([[9, null]], $states);
        try {
            app(UpstreamFileDownloader::class)->download($asset, $this->fixtures.'/oversized', 8, fn () => null);
            $this->fail('Unknown length exceeded the byte limit.');
        } catch (DownloadException $exception) {
            $this->assertSame('media_too_large', $exception->publicCode);
        }
    }

    public function test_expired_or_changed_resolution_fails_without_downloading_tracks(): void
    {
        foreach ([410, 200] as $status) {
            $plan = $this->plan();
            Http::fake(fn ($request) => Http::response(['data' => [
                ...$plan['sources'][0], 'request_id' => $request['request_id'],
                'url' => 'https://rr1.googlevideo.com/video', 'video_codec' => 'vp9',
            ]], $status));
            try {
                app(DownloadPipeline::class)->prepare($plan, fn () => null);
                $this->fail('Stale format was accepted.');
            } catch (DownloadException $exception) {
                $this->assertSame('format_unavailable', $exception->publicCode);
            }
            Http::assertSentCount(1);
            $this->assertDirectoryDoesNotExist(storage_path('app/private/downloads/'.$plan['_directory']));
        }
    }

    public function test_source_client_is_forwarded_with_the_signed_youtube_track_plan(): void
    {
        $plan = $this->plan();
        $plan['sources'][0]['source_client'] = 'default';
        $plan['sources'][1]['source_client'] = 'default';
        Http::fake(function ($request) use ($plan) {
            if (str_ends_with($request->url(), '/v1/youtube/resolve')) {
                $this->assertSame('default', $request['source_client']);
                $id = $request['format_id'];
                $source = $plan['sources'][$id === '137' ? 0 : 1];

                return Http::response(['data' => [
                    ...$source, 'request_id' => $request['request_id'],
                    'url' => 'https://rr1.googlevideo.com/'.$id,
                    'mime_type' => $id === '137' ? 'video/mp4' : 'audio/mp4',
                ]]);
            }

            return Http::response('', 503);
        });
        try {
            app(DownloadPipeline::class)->prepare($plan, fn () => null);
            $this->fail('Invalid sources must not produce an artifact.');
        } catch (DownloadException $exception) {
            $this->assertSame('upstream_unavailable', $exception->publicCode);
        }
    }

    public function test_ready_youtube_artifact_survives_repeated_range_access_until_token_expiry(): void
    {
        $plan = $this->plan();
        $directory = storage_path('app/private/downloads/'.$plan['_directory']);
        File::makeDirectory($directory, 0700, true);
        $path = $directory.'/output.mp4';
        File::put($path, '123456789');
        try {
            $token = app(DownloadAssetStore::class)->issue([
                'mode' => 'local_file', 'provider' => 'youtube', 'path' => $path,
                'mime_type' => 'video/mp4', 'filename' => 'sample-1080p.mp4',
            ]);
            for ($i = 0; $i < 2; $i++) {
                $this->withHeader('Range', 'bytes=0-0')->get('/api/downloads/'.$token)
                    ->assertStatus(206)->assertHeader('Content-Type', 'video/mp4')
                    ->assertHeader('Content-Range', 'bytes 0-0/9');
                $this->assertFileExists($path);
            }
            $this->travel(11)->minutes();
            $this->get('/api/downloads/'.$token)->assertGone();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_cleanup_reaches_expired_artifacts_beyond_recent_directories_and_is_bounded(): void
    {
        $root = storage_path('app/private/downloads');
        File::ensureDirectoryExists($root);
        $paths = [];
        try {
            for ($i = 0; $i < 56; $i++) {
                $path = $root.'/'.sprintf('pr48-test-%03d', $i);
                File::makeDirectory($path);
                $paths[] = $path;
                touch($path, now()->subHours($i < 26 ? 0 : 3)->timestamp);
            }
            app(RuntimeArtifactCleanup::class)->downloads();
            $this->assertSame(31, count(array_filter($paths, 'is_dir')));
            $this->assertDirectoryExists($paths[0]);
            app(RuntimeArtifactCleanup::class)->downloads();
            $this->assertSame(26, count(array_filter($paths, 'is_dir')));
        } finally {
            foreach ($paths as $path) {
                File::deleteDirectory($path);
            }
        }
    }

    private function video(int $height, string $container = 'mp4'): array
    {
        return ['format_id' => 'v'.$height, 'height' => $height, 'container' => $container,
            'video_codec' => $container === 'mp4' ? 'avc1.640028' : 'vp9', 'preference' => $container === 'mp4' ? 0 : 3,
            'has_audio' => false, 'requires_merge' => true, 'fps' => 30, 'bitrate_kbps' => 1000];
    }

    private function audio(string $container = 'm4a'): array
    {
        return ['format_id' => $container === 'm4a' ? '140' : '251', 'container' => $container,
            'audio_codec' => $container === 'm4a' ? 'mp4a.40.2' : 'opus', 'bitrate_kbps' => 128];
    }

    private function plan(string $container = 'mp4'): array
    {
        $base = ['mode' => 'youtube_direct', 'provider' => 'youtube', 'normalized_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];

        return ['mode' => 'youtube_merge', 'container' => $container, 'filename' => 'sample-1080p.'.$container,
            '_directory' => bin2hex(random_bytes(16)), 'sources' => [
                [...$base, 'format_id' => '137', 'video_codec' => $container === 'mp4' ? 'avc1.640028' : 'vp9', 'audio_codec' => 'none'],
                [...$base, 'format_id' => '140', 'video_codec' => 'none', 'audio_codec' => $container === 'mp4' ? 'mp4a.40.2' : 'opus'],
            ]];
    }

    private function fakeSources(array $plan, string $video, string $audio, bool $audioFails = false): void
    {
        Http::fake(function ($request) use ($plan, $video, $audio, $audioFails) {
            if (str_ends_with($request->url(), '/v1/youtube/resolve')) {
                $id = $request['format_id'];
                $source = $plan['sources'][$id === '137' ? 0 : 1];

                return Http::response(['data' => [
                    ...$source, 'request_id' => $request['request_id'],
                    'url' => 'https://rr1.googlevideo.com/'.$id,
                    'mime_type' => ($id === '137' ? 'video/' : 'audio/').$plan['container'],
                ]]);
            }
            $isVideo = str_ends_with($request->url(), '/137');
            $body = $isVideo ? $video : $audio;

            return Http::response($body, ! $isVideo && $audioFails ? 503 : 200, [
                'Content-Type' => ($isVideo ? 'video/' : 'audio/').$plan['container'], 'Content-Length' => strlen($body),
            ]);
        });
    }

    private function process(array $args): string
    {
        $process = new Process($args);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }
}
