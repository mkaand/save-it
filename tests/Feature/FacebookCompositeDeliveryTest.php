<?php

namespace Tests\Feature;

use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadPipeline;
use App\Services\Downloads\ShareMediaPreparationStore;
use App\Services\Downloads\UpstreamUrlPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FacebookCompositeDeliveryTest extends TestCase
{
    private array $directories = [];

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            File::delete($file);
        }
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_facebook_composite_download_and_share_have_audio_and_strict_final_file_ranges(): void
    {
        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('FFmpeg integration runs in the application validation image.');
        }
        $directory = storage_path('app/private/downloads/facebook-test-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700, true);
        $this->directories[] = $directory;
        $video = $directory.'/video.mp4';
        $audio = $directory.'/audio.m4a';
        $this->command(['/usr/bin/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=size=96x64:rate=25:duration=1', '-an', '-c:v', 'libx264', '-movflags', '+faststart', $video]);
        $this->command(['/usr/bin/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-vn', '-c:a', 'aac', '-metadata', 'creation_time=2020-01-01T00:00:00Z', $audio]);
        $videoBytes = file_get_contents($video);
        $audioBytes = file_get_contents($audio);
        $this->app->bind(UpstreamUrlPolicy::class, fn () => new class extends UpstreamUrlPolicy
        {
            protected function resolveAddresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
        Http::fake(function ($request) use ($videoBytes, $audioBytes) {
            $bytes = str_contains($request->url(), 'audio') ? $audioBytes : $videoBytes;

            return Http::response($bytes, 206, ['Content-Type' => 'video/mp4', 'Content-Length' => strlen($bytes), 'Content-Range' => 'bytes 0-'.(strlen($bytes) - 1).'/'.strlen($bytes)]);
        });
        $result = app(DownloadPipeline::class)->prepare([
            'mode' => 'facebook_merge', 'filename' => 'Facebook.mp4', 'sources' => [
                ['provider' => 'facebook', 'upstream_url' => 'https://video.edge.fna.fbcdn.net/video.mp4?signature=private-video', 'mime_type' => 'video/mp4'],
                ['provider' => 'facebook', 'upstream_url' => 'https://video.edge.fna.fbcdn.net/audio.mp4?signature=private-audio', 'mime_type' => 'audio/mp4'],
            ],
        ], fn () => null);
        $this->directories[] = dirname($result['path']);
        $this->assertSame(['output.mp4'], array_map('basename', glob(dirname($result['path']).'/*')));
        $this->assertAv($result['path']);
        $this->assertSame($this->streamHash($video, '0:v:0'), $this->streamHash($result['path'], '0:v:0'));
        $this->assertSame($this->streamHash($audio, '0:a:0'), $this->streamHash($result['path'], '0:a:0'));

        $token = app(DownloadAssetStore::class)->issue(['mode' => 'local_file', 'provider' => 'facebook', ...$result]);
        $url = '/api/downloads/'.$token;
        $full = $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame(file_get_contents($result['path']), $full->streamedContent());
        foreach (['0-0' => 1, '10-29' => 20] as $range => $length) {
            $response = $this->withHeader('Range', 'bytes='.$range)->get($url)->assertStatus(206)
                ->assertHeader('Content-Range', 'bytes '.$range.'/'.$result['size'])->assertHeader('Content-Length', (string) $length);
            $this->assertSame(substr(file_get_contents($result['path']), (int) explode('-', $range)[0], $length), $response->streamedContent());
        }
        $this->flushHeaders();
        $share = $this->postJson('/api/share-preparations', ['token' => $token])->assertOk()->json('data.url');
        $prepared = app(ShareMediaPreparationStore::class)->resolve(basename($share));
        $this->files[] = $prepared['path'];
        $this->assertAv($prepared['path']);
        $this->assertSame(104857600, config('services.downloads.share_max_file_bytes'));
        $this->assertStringNotContainsString('fbcdn.net', $share);
        Http::assertSentCount(2);
    }

    private function assertAv(string $path): void
    {
        $data = json_decode($this->command(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path]), true);
        $this->assertCount(2, $data['streams']);
        $this->assertSame(['video', 'audio'], array_column($data['streams'], 'codec_type'));
        $this->assertSame(96, $data['streams'][0]['width']);
        $this->assertSame(64, $data['streams'][0]['height']);
        $this->assertGreaterThan(0.9, (float) $data['format']['duration']);
        foreach ($data['streams'] as $stream) {
            $this->assertArrayNotHasKey('creation_time', $stream['tags'] ?? []);
        }
    }

    private function streamHash(string $path, string $map): string
    {
        return trim($this->command(['/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-map', $map, '-c', 'copy', '-f', 'hash', '-']));
    }

    private function command(array $command): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [], ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);

        return $out;
    }
}
