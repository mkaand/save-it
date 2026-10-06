<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class YouTubeMuxService
{
    public function __construct(
        private readonly UpstreamFileDownloader $downloader,
        private readonly YouTubeSourceResolver $resolver,
    ) {}

    public function prepare(array $plan, string $directory, callable $progress): array
    {
        $container = $plan['container'] ?? null;
        $video = $plan['sources'][0] ?? [];
        $audio = $plan['sources'][1] ?? [];
        if (! in_array($container, ['mp4', 'webm'], true)
            || ($video['provider'] ?? null) !== 'youtube'
            || ($audio['provider'] ?? null) !== 'youtube'
            || ($video['normalized_url'] ?? null) !== ($audio['normalized_url'] ?? null)
            || ($video['audio_codec'] ?? null) !== 'none'
            || ($audio['video_codec'] ?? null) !== 'none'
            || ! YouTubeOutputSelector::compatible($container, $video['video_codec'] ?? '', $audio['audio_codec'] ?? '')) {
            throw new DownloadException('invalid_download_token', 410, 'The download plan is invalid.');
        }
        $limit = (int) config('services.downloads.max_file_bytes');
        if (($plan['expected_size'] ?? 0) > $limit) {
            throw new DownloadException('media_too_large', 413, 'The prepared download exceeds the size limit.');
        }
        $available = disk_free_space($directory);
        if ($available === false || $available < 2 * $limit + 64 * 1024 * 1024) {
            throw new DownloadException('insufficient_storage', 507, 'There is not enough temporary storage to prepare this download.');
        }

        $deadline = microtime(true) + max(10, (int) config('services.downloads.job_timeout_seconds') - 300);
        $bytes = 0;
        foreach ([$video, $audio] as $index => $source) {
            if ($bytes >= $limit) {
                throw new DownloadException('media_too_large', 413, 'The prepared download exceeds the size limit.');
            }
            $stage = $index === 0 ? 'Downloading video' : 'Downloading audio';
            $progress($stage, null);
            $resolved = $this->resolver->resolve($source);
            // Estimates never delimit a response. Count and validate actual bytes.
            $resolved['expected_size'] = null;
            $last = -1;
            $bytes += $this->downloader->download($resolved, "{$directory}/track-{$index}", $limit - $bytes,
                function (int $received, ?int $total) use ($stage, $progress, &$last, $deadline): void {
                    if (microtime(true) > $deadline) {
                        throw new DownloadException('conversion_timeout', 504, 'The media preparation exceeded its processing deadline.');
                    }
                    $percent = $total !== null && $total > 0 ? min(100, (int) floor(100 * $received / $total)) : null;
                    if ($percent !== $last) {
                        $last = $percent;
                        $progress($stage, $percent);
                    }
                });
        }
        $progress('Merging', null);
        $partial = "{$directory}/output.part";
        $command = [
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-n',
            '-protocol_whitelist', 'file,pipe', '-i', "{$directory}/track-0",
            '-protocol_whitelist', 'file,pipe', '-i', "{$directory}/track-1",
            '-map', '0:v:0', '-map', '1:a:0', '-c', 'copy',
            '-map_metadata', '-1', '-map_chapters', '-1',
            ...($container === 'mp4' ? ['-movflags', '+faststart'] : []),
            '-f', $container, $partial,
        ];
        $process = new Process($command);
        $process->setTimeout(120);
        $process->disableOutput();
        if ($process->run() !== 0) {
            throw new DownloadException('conversion_failed', 422, 'The selected media could not be merged.');
        }
        $progress('Finalizing', null);
        $size = filesize($partial);
        if (! is_int($size) || $size < 1 || $size > $limit) {
            throw new DownloadException('media_too_large', 413, 'The prepared download exceeds the size limit.');
        }
        $probe = new Process(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $partial]);
        $probe->setTimeout(10);
        if ($probe->run() !== 0) {
            throw new DownloadException('conversion_failed', 422, 'The merged media could not be verified.');
        }
        $details = json_decode($probe->getOutput(), true);
        $streams = $details['streams'] ?? [];
        if (count($streams) !== 2 || ($streams[0]['codec_type'] ?? null) !== 'video'
            || ($streams[1]['codec_type'] ?? null) !== 'audio'
            || (float) ($details['format']['duration'] ?? 0) <= 0) {
            throw new DownloadException('conversion_failed', 422, 'The merged media must contain video and audio.');
        }
        $path = "{$directory}/output.{$container}";
        if (! rename($partial, $path)) {
            throw new DownloadException('job_failed', 500, 'The merged media could not be finalized.');
        }
        File::delete(["{$directory}/track-0", "{$directory}/track-1"]);

        return ['path' => $path, 'filename' => $plan['filename'], 'mime_type' => "video/{$container}", 'size' => $size];
    }
}
