<?php

namespace App\Services;

use App\Enums\MediaPlatform;
use App\Services\Extractor\ExtractorClient;

final class MediaUrlAnalyzer
{
    public function __construct(private readonly ExtractorClient $extractor) {}

    /**
     * @return array{
     *     platform: string,
     *     platform_label: string,
     *     media_type: string,
     *     url: string,
     *     title: string,
     *     thumbnail_url: ?string,
     *     status: string,
     *     outputs: array<int, array{id: string, label: string, detail: string, available: bool}>
     * }
     */
    public function analyze(string $input): array
    {
        $recognition = $this->extractor->recognize(trim($input));
        $platform = $recognition->platform;
        $parts = parse_url($recognition->normalizedUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $videoId = $this->youtubeVideoId($host, $path, $parts['query'] ?? null);
        $normalizedUrl = $this->normalizedUrl(
            $host,
            $path,
            $parts['query'] ?? null,
            $platform,
            $videoId,
        );

        return [
            'platform' => $platform->value,
            'platform_label' => $platform->label(),
            'media_type' => $platform->mediaType(),
            'url' => $normalizedUrl,
            'title' => $this->title($platform),
            'thumbnail_url' => $videoId === null ? null : "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'status' => 'preview',
            'outputs' => $this->outputs($platform),
        ];
    }

    private function youtubeVideoId(string $host, string $path, ?string $query): ?string
    {
        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'], true)) {
            return null;
        }

        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (str_starts_with($path, '/shorts/')) {
            $candidate = explode('/', substr($path, strlen('/shorts/')))[0] ?? null;
        } elseif ($path === '/watch' && $query !== null) {
            parse_str($query, $parameters);
            $candidate = is_string($parameters['v'] ?? null) ? $parameters['v'] : null;
        }

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1
            ? $candidate
            : null;
    }

    private function normalizedUrl(
        string $host,
        string $path,
        ?string $query,
        MediaPlatform $platform,
        ?string $videoId,
    ): string {
        if ($videoId !== null) {
            return $platform === MediaPlatform::YouTubeShorts
                ? "https://www.youtube.com/shorts/{$videoId}"
                : "https://www.youtube.com/watch?v={$videoId}";
        }

        $normalizedPath = $path === '' ? '/' : $path;
        $normalizedQuery = $query === null || $query === '' ? '' : "?{$query}";

        return "https://{$host}{$normalizedPath}{$normalizedQuery}";
    }

    private function title(MediaPlatform $platform): string
    {
        return match ($platform) {
            MediaPlatform::YouTube => 'YouTube media',
            MediaPlatform::YouTubeShorts => 'YouTube Short',
            MediaPlatform::Instagram => 'Instagram media',
            MediaPlatform::TikTok => 'TikTok media',
            MediaPlatform::X => 'X media',
            MediaPlatform::Facebook => 'Facebook media',
            MediaPlatform::LinkedIn => 'LinkedIn media',
        };
    }

    /**
     * @return array<int, array{id: string, label: string, detail: string, available: bool}>
     */
    private function outputs(MediaPlatform $platform): array
    {
        if (! in_array($platform, [MediaPlatform::YouTube, MediaPlatform::YouTubeShorts], true)) {
            return [[
                'id' => 'format-discovery',
                'label' => 'Format discovery',
                'detail' => 'Planned for the media engine',
                'available' => false,
            ]];
        }

        return [
            ['id' => 'mp4-h264', 'label' => 'MP4', 'detail' => 'H.264 preferred', 'available' => false],
            ['id' => 'm4a', 'label' => 'M4A', 'detail' => 'Audio output', 'available' => false],
            ['id' => 'mp3', 'label' => 'MP3', 'detail' => 'Conversion planned', 'available' => false],
            ['id' => 'thumbnail', 'label' => 'Thumbnail', 'detail' => 'Image download planned', 'available' => false],
        ];
    }
}
