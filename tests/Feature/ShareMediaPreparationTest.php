<?php

namespace Tests\Feature;

use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\ShareMediaPreparationStore;
use Illuminate\Support\Facades\File;
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

    public function test_share_limit_is_fifty_mib(): void
    {
        $this->assertSame(52_428_800, config('services.downloads.share_max_file_bytes'));
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
            'path' => $source,
            'filename' => 'Rick Astley.mp4',
            'mime_type' => 'video/mp4',
        ]);

        $response = $this->postJson('/api/share-preparations', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.mime_type', 'video/mp4')
            ->assertJsonPath('data.filename', 'Rick Astley.mp4');
        $url = $response->json('data.url');
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
        $this->postJson('/api/share-preparations', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'share_unavailable');

        $store = $this->app->make(ShareMediaPreparationStore::class);
        $directory = $store->directory();
        File::ensureDirectoryExists($directory, 0700, true);
        $orphan = $directory.'/'.str_repeat('a', 48).'.mp4';
        file_put_contents($orphan, 'orphan');
        touch($orphan, time() - 700);
        $store->cleanupExpired();
        $this->assertFileDoesNotExist($orphan);
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

        $this->postJson('/api/share-preparations', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'share_unavailable');
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

        $this->postJson('/api/share-preparations', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'share_unavailable');
        $this->assertSame([], glob($this->app->make(ShareMediaPreparationStore::class)->directory().'/*.mp4') ?: []);
    }

    private function createMp4Fixture(string $path): void
    {
        $this->command([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=64x64:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=1000:sample_rate=44100',
            '-t', '1', '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'libx264', '-c:a', 'aac',
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
