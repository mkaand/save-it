<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\File;
use ZipArchive;

final class DownloadPipeline
{
    public function __construct(
        private readonly UpstreamFileDownloader $downloader,
        private readonly YouTubeSourceResolver $youtube,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{path: string, filename: string, mime_type: string, size: int}
     */
    public function prepare(array $plan, callable $progress): array
    {
        $directory = storage_path('app/private/downloads/'.bin2hex(random_bytes(16)));
        $this->ensureDiskSpace($plan);
        File::makeDirectory($directory, 0700, true);

        try {
            return match ($plan['mode'] ?? null) {
                'youtube_merge' => $this->merge($plan, $directory, $progress),
                'youtube_mp3' => $this->mp3($plan, $directory, $progress),
                'zip' => $this->zip($plan, $directory, $progress),
                default => throw new DownloadException(
                    'invalid_download_token',
                    410,
                    'The download plan is invalid.',
                ),
            };
        } catch (\Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $plan */
    private function merge(array $plan, string $directory, callable $progress): array
    {
        $progress('Downloading streams', 15);
        $video = $this->youtube->resolve($this->source($plan, 0));
        $audio = $this->youtube->resolve($this->source($plan, 1));
        $videoPath = "{$directory}/video.input";
        $audioPath = "{$directory}/audio.input";
        $limit = (int) config('services.downloads.max_file_bytes');
        $this->downloader->download($video, $videoPath, $limit);
        $this->downloader->download($audio, $audioPath, $limit);

        $progress('Merging', 70);
        $output = "{$directory}/output.mp4";
        $this->ffmpeg([
            '-i', $videoPath,
            '-i', $audioPath,
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-c', 'copy',
            '-movflags', '+faststart',
            '-threads', '2',
            '-fs', (string) config('services.downloads.max_file_bytes'),
            $output,
        ]);

        return $this->result($output, $plan, 'video/mp4');
    }

    /** @param array<string, mixed> $plan */
    private function mp3(array $plan, string $directory, callable $progress): array
    {
        $progress('Downloading audio', 20);
        $source = $this->youtube->resolve($this->source($plan, 0));
        $input = "{$directory}/audio.input";
        $this->downloader->download(
            $source,
            $input,
            (int) config('services.downloads.max_file_bytes'),
        );
        $bitrate = $plan['bitrate_kbps'] ?? null;
        if (! in_array($bitrate, [128, 192, 256, 320], true)) {
            throw new DownloadException('invalid_download_token', 410, 'The MP3 plan is invalid.');
        }

        $progress('Converting to MP3', 70);
        $output = "{$directory}/output.mp3";
        $this->ffmpeg([
            '-i', $input,
            '-vn',
            '-codec:a', 'libmp3lame',
            '-b:a', "{$bitrate}k",
            '-threads', '2',
            '-fs', (string) config('services.downloads.max_file_bytes'),
            $output,
        ]);

        return $this->result($output, $plan, 'audio/mpeg');
    }

    /** @param array<string, mixed> $plan */
    private function zip(array $plan, string $directory, callable $progress): array
    {
        $sources = $plan['sources'] ?? null;
        $maximum = (int) config('services.downloads.max_zip_assets');
        if (! is_array($sources) || $sources === [] || count($sources) > $maximum) {
            throw new DownloadException('invalid_download_token', 410, 'The ZIP plan is invalid.');
        }
        $zipPath = "{$directory}/output.zip";
        $archive = new ZipArchive;
        if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new DownloadException('job_failed', 500, 'The ZIP archive could not be created.');
        }
        $total = 0;
        try {
            foreach (array_values($sources) as $index => $source) {
                if (! is_array($source)) {
                    throw new DownloadException('invalid_download_token', 410, 'The ZIP plan is invalid.');
                }
                $progress('Downloading media', 10 + (int) (($index / count($sources)) * 70));
                $path = "{$directory}/source-{$index}";
                $total += $this->downloader->download(
                    $source,
                    $path,
                    (int) config('services.downloads.max_zip_bytes') - $total,
                );
                $name = $this->archiveName((string) ($source['filename'] ?? "media-{$index}"), $index);
                if (! $archive->addFile($path, $name)) {
                    throw new DownloadException('job_failed', 500, 'The ZIP archive could not be created.');
                }
            }
        } finally {
            $archive->close();
        }
        foreach (glob("{$directory}/source-*") ?: [] as $sourcePath) {
            File::delete($sourcePath);
        }

        return $this->result($zipPath, $plan, 'application/zip');
    }

    /** @param array<string, mixed> $plan */
    private function source(array $plan, int $index): array
    {
        $source = $plan['sources'][$index] ?? null;
        if (! is_array($source)) {
            throw new DownloadException('invalid_download_token', 410, 'The download plan is invalid.');
        }

        return $source;
    }

    /** @param array<int, string> $arguments */
    private function ffmpeg(array $arguments): void
    {
        $command = ['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', ...$arguments];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, [], ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new DownloadException(
                'conversion_failed',
                503,
                'The media conversion service is unavailable.',
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $timeout = max(10, (int) config('services.downloads.job_timeout_seconds'));
        do {
            $status = proc_get_status($process);
            stream_get_contents($pipes[1], 65_536);
            stream_get_contents($pipes[2], 65_536);
            if (! $status['running']) {
                break;
            }
            if ((microtime(true) - $started) > $timeout) {
                proc_terminate($process, 15);
                usleep(200_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new DownloadException(
                    'conversion_timeout',
                    504,
                    'The media conversion exceeded its processing deadline.',
                );
            }
            usleep(50_000);
        } while (true);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = $status['exitcode'];
        proc_close($process);
        if ($exitCode !== 0) {
            throw new DownloadException(
                'conversion_failed',
                422,
                'The selected media could not be prepared.',
            );
        }
    }

    /** @param array<string, mixed> $plan */
    private function result(string $path, array $plan, string $mime): array
    {
        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > (int) config('services.downloads.max_zip_bytes')) {
            throw new DownloadException('job_failed', 500, 'The prepared file is invalid.');
        }

        return [
            'path' => $path,
            'filename' => (string) ($plan['filename'] ?? basename($path)),
            'mime_type' => $mime,
            'size' => $size,
        ];
    }

    private function archiveName(string $name, int $index): string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '-', basename($name)) ?: "media-{$index}";

        return sprintf('%02d-%s', $index + 1, mb_substr($name, 0, 160));
    }

    /** @param array<string, mixed> $plan */
    private function ensureDiskSpace(array $plan): void
    {
        $available = disk_free_space(storage_path('app/private'));
        if ($available === false) {
            throw new DownloadException(
                'storage_unavailable',
                503,
                'Temporary media storage is unavailable.',
            );
        }

        $estimated = 0;
        foreach ($plan['sources'] ?? [] as $source) {
            if (is_array($source) && is_int($source['expected_size'] ?? null)) {
                $estimated += max(0, $source['expected_size']);
            }
        }
        $required = max(
            64 * 1024 * 1024,
            min((int) config('services.downloads.max_zip_bytes'), $estimated * 2),
        );
        if ($available < $required) {
            throw new DownloadException(
                'insufficient_storage',
                507,
                'There is not enough temporary storage to prepare this download.',
            );
        }
    }
}
