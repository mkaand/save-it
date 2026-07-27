<?php

namespace App\Services;

use App\Enums\MediaPlatform;
use InvalidArgumentException;

final class MediaUrlAnalyzer
{
    /**
     * @var array<string, MediaPlatform>
     */
    private const HOSTS = [
        'youtube.com' => MediaPlatform::YouTube,
        'www.youtube.com' => MediaPlatform::YouTube,
        'm.youtube.com' => MediaPlatform::YouTube,
        'music.youtube.com' => MediaPlatform::YouTube,
        'youtu.be' => MediaPlatform::YouTube,
        'instagram.com' => MediaPlatform::Instagram,
        'www.instagram.com' => MediaPlatform::Instagram,
        'tiktok.com' => MediaPlatform::TikTok,
        'www.tiktok.com' => MediaPlatform::TikTok,
        'vm.tiktok.com' => MediaPlatform::TikTok,
        'vt.tiktok.com' => MediaPlatform::TikTok,
        'x.com' => MediaPlatform::X,
        'www.x.com' => MediaPlatform::X,
        'twitter.com' => MediaPlatform::X,
        'www.twitter.com' => MediaPlatform::X,
        'facebook.com' => MediaPlatform::Facebook,
        'www.facebook.com' => MediaPlatform::Facebook,
        'm.facebook.com' => MediaPlatform::Facebook,
        'fb.watch' => MediaPlatform::Facebook,
        'linkedin.com' => MediaPlatform::LinkedIn,
        'www.linkedin.com' => MediaPlatform::LinkedIn,
    ];

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
        $url = trim($input);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid media URL.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Enter a valid media URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URLs containing embedded credentials are not allowed.');
        }

        if (isset($parts['port'])) {
            throw new InvalidArgumentException('URLs with custom ports are not supported.');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $this->assertSafeHost($host);

        $platform = self::HOSTS[$host] ?? null;

        if ($platform === null) {
            throw new InvalidArgumentException('This media host is not supported yet.');
        }

        $path = $parts['path'] ?? '/';

        if ($platform === MediaPlatform::YouTube && str_starts_with($path, '/shorts/')) {
            $platform = MediaPlatform::YouTubeShorts;
        }

        $videoId = $this->youtubeVideoId($host, $path, $parts['query'] ?? null);
        $normalizedUrl = $this->normalizedUrl($host, $path, $parts['query'] ?? null, $platform, $videoId);

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

    private function assertSafeHost(string $host): void
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('Local and private addresses are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('IP address URLs are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Enter a URL with a valid hostname.');
        }
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
