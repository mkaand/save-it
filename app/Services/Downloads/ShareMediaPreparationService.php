<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class ShareMediaPreparationService
{
    public function __construct(
        private readonly DownloadAssetStore $downloads,
        private readonly YouTubeSourceResolver $youtube,
        private readonly UpstreamFileDownloader $downloader,
        private readonly ShareMediaPreparationStore $preparations,
    ) {}

    /** @return array{id: string, filename: string, provider: string} */
    public function prepare(string $token, ?callable $onState = null): array
    {
        return $this->prepareAsset($this->downloads->resolve($token), $onState);
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  (callable(string, ?int): void)|null  $onState
     * @return array{id: string, filename: string, provider: string}
     */
    public function prepareAsset(array $asset, ?callable $onState = null): array
    {
        $onState ??= static function (): void {};
        if (($asset['mode'] ?? null) === 'youtube_direct') {
            $asset = $this->youtube->resolve($asset);
        }
        if (($asset['mime_type'] ?? null) !== 'video/mp4') {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }

        $limit = max(1, (int) config('services.downloads.share_max_file_bytes'));
        $directory = $this->preparations->directory();
        File::ensureDirectoryExists($directory, 0700, true);
        $work = $directory.DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(16));
        File::ensureDirectoryExists($work, 0700, true);
        $input = $work.'/source.mp4';
        $output = $work.'/prepared.mp4';

        try {
            $onState('Downloading', null);
            if (($asset['mode'] ?? null) === 'local_file') {
                $this->copyLocalSource($asset, $input, $limit);
            } elseif (($asset['mode'] ?? null) === 'proxy' || ($asset['mode'] ?? null) === 'youtube_direct') {
                $this->downloader->download($asset, $input, $limit);
            } else {
                throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
            }

            $this->remux($input, $output, ($asset['provider'] ?? null) === 'facebook', $onState);
            $size = filesize($output);
            if (! is_int($size) || $size < 1 || $size > $limit) {
                throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
            }
            $onState('Finalizing', null);
            $id = strtolower(bin2hex(random_bytes(24)));
            $path = $directory.DIRECTORY_SEPARATOR.$id.'.mp4';
            if (! rename($output, $path)) {
                throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
            }
            chmod($path, 0600);

            return [
                'id' => $this->preparations->issue($path, $this->filename($asset)),
                'filename' => $this->filename($asset),
                'provider' => is_string($asset['provider'] ?? null) ? $asset['provider'] : 'unknown',
            ];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @param array<string, mixed> $asset */
    private function copyLocalSource(array $asset, string $destination, int $limit): void
    {
        $path = $asset['path'] ?? null;
        $root = realpath(storage_path('app/private/downloads'));
        $source = is_string($path) ? realpath($path) : false;
        $size = $source === false ? false : filesize($source);
        if ($root === false || $source === false || ! str_starts_with($source, $root.DIRECTORY_SEPARATOR) || ! is_int($size) || $size < 1 || $size > $limit || ! copy($source, $destination)) {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }
        chmod($destination, 0600);
    }

    /** @param callable(string, ?int): void $onState */
    private function remux(string $input, string $output, bool $facebook, callable $onState): void
    {
        $onState('Inspecting', null);
        $this->run([
            (string) config('services.downloads.share_ffmpeg_binary'),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input,
            '-map', '0:v?', '-map', '0:a?', '-map_metadata', '-1', '-map_chapters', '-1',
            '-c', 'copy', '-movflags', '+faststart', $output,
        ]);
        if (! is_file($output)) {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }
        if (! $facebook) {
            return;
        }
        $details = $this->videoDetails($output);
        if ($details['compatible']) {
            return;
        }
        $compatible = dirname($output).'/ios-compatible.mp4';
        $this->transcodeForIos($output, $compatible, $details['duration'], $onState);
        if (! rename($compatible, $output)) {
            throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
        }
    }

    /** @return array{compatible: bool, duration: float|null} */
    private function videoDetails(string $path): array
    {
        $stdout = $this->run([
            (string) config('services.downloads.share_ffprobe_binary'),
            '-v', 'error', '-show_entries', 'stream=codec_name,pix_fmt:format=duration', '-of', 'json', $path,
        ]);
        $probe = json_decode($stdout, true);
        $stream = is_array($probe) ? ($probe['streams'][0] ?? null) : null;
        $format = is_array($probe) ? ($probe['format'] ?? null) : null;
        $duration = is_array($format) && is_numeric($format['duration'] ?? null) && (float) $format['duration'] > 0
            ? (float) $format['duration']
            : null;

        return [
            'compatible' => is_array($stream)
                && ($stream['codec_name'] ?? null) === 'h264'
                && ($stream['pix_fmt'] ?? null) === 'yuv420p',
            'duration' => $duration,
        ];
    }

    /** @param callable(string, ?int): void $onState */
    private function transcodeForIos(string $input, string $output, ?float $duration, callable $onState): void
    {
        $onState('Converting', $duration === null ? null : 0);
        $buffer = '';
        $last = -1;
        $this->run([
            (string) config('services.downloads.share_ffmpeg_binary'),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input,
            '-map', '0:v:0', '-map', '0:a?', '-map_metadata', '-1', '-map_chapters', '-1',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
            '-c:a', 'copy', '-movflags', '+faststart', '-progress', 'pipe:1', '-nostats', $output,
        ], function (string $type, string $data) use (&$buffer, &$last, $duration, $onState): void {
            if ($type !== Process::OUT) {
                return;
            }
            $buffer .= $data;
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);
                $progress = self::conversionProgressFromLine($line, $duration);
                if ($progress !== null && $progress > $last) {
                    $last = $progress;
                    $onState('Converting', $progress);
                }
            }
        });
        if (! is_file($output)) {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }
    }

    public static function conversionProgressFromLine(string $line, ?float $duration): ?int
    {
        if ($duration === null || $duration <= 0 || preg_match('/^out_time_(?:us|ms)=([0-9]+)$/', $line, $matches) !== 1) {
            return null;
        }

        // FFmpeg's historic out_time_ms field is expressed in microseconds.
        return min(99, max(0, (int) floor(((int) $matches[1] / 1_000_000) / $duration * 100)));
    }

    /** @param array<int, string> $command */
    private function run(array $command, ?callable $onOutput = null): string
    {
        try {
            $process = new Process($command);
            // Finish/terminate FFmpeg before Laravel's worker timeout so the
            // child is not left behind if the worker is recycled.
            $process->setTimeout(max(30, (int) config('services.downloads.job_timeout_seconds') - 15));
            $process->setIdleTimeout(null);
            $process->run($onOutput);
        } catch (\Throwable) {
            throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
        }
        if (! $process->isSuccessful()) {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }

        return $process->getOutput();
    }

    /** @param array<string, mixed> $asset */
    private function filename(array $asset): string
    {
        $filename = $asset['filename'] ?? null;

        return is_string($filename) && $filename !== '' ? $filename : 'save-it-media.mp4';
    }
}
