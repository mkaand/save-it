<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\File;

final class ShareMediaPreparationService
{
    public function __construct(
        private readonly DownloadAssetStore $downloads,
        private readonly YouTubeSourceResolver $youtube,
        private readonly UpstreamFileDownloader $downloader,
        private readonly ShareMediaPreparationStore $preparations,
    ) {}

    /** @return array{id: string, filename: string} */
    public function prepare(string $token): array
    {
        $asset = $this->downloads->resolve($token);
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
            if (($asset['mode'] ?? null) === 'local_file') {
                $this->copyLocalSource($asset, $input, $limit);
            } elseif (($asset['mode'] ?? null) === 'proxy' || ($asset['mode'] ?? null) === 'youtube_direct') {
                $this->downloader->download($asset, $input, $limit);
            } else {
                throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
            }

            $this->remux($input, $output, $limit);
            $size = filesize($output);
            if (! is_int($size) || $size < 1 || $size > $limit) {
                throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
            }
            $id = strtolower(bin2hex(random_bytes(24)));
            $path = $directory.DIRECTORY_SEPARATOR.$id.'.mp4';
            if (! rename($output, $path)) {
                throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
            }
            chmod($path, 0600);

            return ['id' => $this->preparations->issue($path, $this->filename($asset)), 'filename' => $this->filename($asset)];
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

    private function remux(string $input, string $output, int $limit): void
    {
        $command = [
            (string) config('services.downloads.share_ffmpeg_binary'),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $input,
            '-map', '0:v?', '-map', '0:a?',
            '-map_metadata', '-1', '-map_chapters', '-1',
            '-c', 'copy',
            '-movflags', '+faststart',
            $output,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, [], ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2], 65_536);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || ! is_file($output)) {
            throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
        }
    }

    /** @param array<string, mixed> $asset */
    private function filename(array $asset): string
    {
        $filename = $asset['filename'] ?? null;

        return is_string($filename) && $filename !== '' ? $filename : 'save-it-media.mp4';
    }
}
