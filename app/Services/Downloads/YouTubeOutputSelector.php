<?php

namespace App\Services\Downloads;

final class YouTubeOutputSelector
{
    /** One complete output per source resolution, never a silent adaptive track. */
    public function select(array $videos, array $audios): array
    {
        usort($audios, fn (array $a, array $b): int => [-(int) ($a['language_preference'] ?? 0), -($a['bitrate_kbps'] ?? 0), $a['format_id']]
            <=> [-(int) ($b['language_preference'] ?? 0), -($b['bitrate_kbps'] ?? 0), $b['format_id']]);
        usort($videos, fn (array $a, array $b): int => $this->rank($a) <=> $this->rank($b));
        $outputs = [];
        foreach ($videos as $video) {
            $height = $video['height'] ?? null;
            if (! is_int($height) || $height < 1 || $height > 2160 || isset($outputs[$height])) {
                continue;
            }
            $container = $video['container'];
            $audio = null;
            if ($video['requires_merge']) {
                foreach ($audios as $candidate) {
                    if (self::compatible($container, $video['video_codec'], $candidate['audio_codec'])) {
                        $audio = $candidate;
                        break;
                    }
                }
                if ($audio === null) {
                    continue;
                }
            } elseif (! $video['has_audio']) {
                continue;
            }
            $outputs[$height] = ['video' => $video, 'audio' => $audio];
        }

        return array_values($outputs);
    }

    public static function compatible(string $container, string $video, string $audio): bool
    {
        return ($container === 'mp4'
                && preg_match('/^(avc1|h264|hev1|hvc1|hevc|av01)/', $video)
                && preg_match('/^(mp4a|aac)/', $audio))
            || ($container === 'webm'
                && preg_match('/^(vp9|vp09|av01|av1)/', $video)
                && in_array($audio, ['opus', 'vorbis'], true));
    }

    private function rank(array $video): array
    {
        return [
            -($video['height'] ?? 0),
            $video['requires_merge'] ? 1 : 0,
            in_array($video['dynamic_range'] ?? null, [null, 'SDR'], true) ? 0 : 1,
            -($video['fps'] ?? 0),
            $video['preference'],
            -($video['bitrate_kbps'] ?? 0),
            $video['format_id'],
        ];
    }
}
