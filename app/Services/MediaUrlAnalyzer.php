<?php

namespace App\Services;

use App\Enums\MediaPlatform;
use App\Services\Extractor\ExtractorClient;
use App\Services\Extractor\ExtractorRecognition;

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
     *     assets: array<int, array<string, mixed>>,
     *     outputs: array<int, array{id: string, label: string, detail: string, available: bool}>
     * }
     */
    public function analyze(string $input): array
    {
        $recognition = $this->extractor->recognize(trim($input));
        $platform = $recognition->platform;

        if (
            $recognition->status === 'ready'
            && in_array($platform, [
                MediaPlatform::X,
                MediaPlatform::Instagram,
                MediaPlatform::YouTube,
                MediaPlatform::YouTubeShorts,
                MediaPlatform::LinkedIn,
            ], true)
        ) {
            return $this->providerResult($recognition);
        }

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
            'assets' => [],
            'outputs' => $this->outputs($platform),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerResult(ExtractorRecognition $recognition): array
    {
        if (in_array(
            $recognition->platform,
            [MediaPlatform::YouTube, MediaPlatform::YouTubeShorts],
            true,
        )) {
            return $this->youtubeResult($recognition);
        }

        if ($recognition->platform === MediaPlatform::LinkedIn) {
            return $this->linkedinResult($recognition);
        }

        $metadata = $recognition->metadata ?? [];
        $text = $metadata['text'] ?? null;
        $handle = $metadata['author_handle'] ?? null;
        $title = is_string($text) && $text !== ''
            ? $text
            : (is_string($handle) && $handle !== ''
                ? "{$recognition->platform->label()} post by @{$handle}"
                : "{$recognition->platform->label()} post");
        $thumbnail = $metadata['thumbnail_url'] ?? null;

        if (! is_string($thumbnail)) {
            $thumbnail = $recognition->assets[0]['thumbnail_url'] ?? null;
        }

        return [
            'platform' => $recognition->platform->value,
            'platform_label' => $recognition->platform->label(),
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $title,
            'thumbnail_url' => is_string($thumbnail) ? $thumbnail : null,
            'status' => 'ready',
            'metadata' => $metadata,
            'assets' => $recognition->assets,
            'capabilities' => $recognition->capabilities,
            'provider_maturity' => $recognition->maturity,
            'warnings' => $recognition->warnings,
            'outputs' => array_map(
                fn (array $asset): array => [
                    'id' => $asset['id'],
                    'label' => match ($asset['type']) {
                        'image' => 'Image',
                        'animated_gif' => 'Animated GIF',
                        default => 'Video',
                    },
                    'detail' => $this->assetDetail($asset),
                    'available' => false,
                ],
                $recognition->assets,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkedinResult(ExtractorRecognition $recognition): array
    {
        $metadata = $recognition->metadata ?? [];
        $title = $metadata['title'] ?? null;
        $description = $metadata['description'] ?? null;
        $author = $metadata['author_name'] ?? null;
        if (! is_string($title) || $title === '') {
            $title = is_string($description) && $description !== ''
                ? mb_substr($description, 0, 160)
                : (is_string($author) && $author !== ''
                    ? "LinkedIn post by {$author}"
                    : 'LinkedIn public post');
        }

        $thumbnail = $metadata['thumbnail_url'] ?? null;
        if (! is_string($thumbnail)) {
            $thumbnail = $recognition->assets[0]['thumbnail_url'] ?? null;
        }

        $outputs = array_map(
            fn (array $asset): array => [
                'id' => $asset['id'],
                'label' => $asset['type'] === 'image' ? 'Image' : 'Video',
                'detail' => $this->assetDetail($asset),
                'available' => false,
            ],
            $recognition->assets,
        );
        if ($outputs === []) {
            $outputs[] = [
                'id' => 'linkedin-metadata',
                'label' => 'Metadata only',
                'detail' => 'No public media asset was exposed',
                'available' => false,
            ];
        }

        return [
            'platform' => MediaPlatform::LinkedIn->value,
            'platform_label' => MediaPlatform::LinkedIn->label(),
            'provider_maturity' => 'beta',
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $title,
            'thumbnail_url' => is_string($thumbnail) ? $thumbnail : null,
            'status' => 'ready',
            'metadata' => $metadata,
            'assets' => $recognition->assets,
            'capabilities' => $recognition->capabilities,
            'warnings' => $recognition->warnings,
            'outputs' => $outputs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeResult(ExtractorRecognition $recognition): array
    {
        $metadata = $recognition->metadata ?? [];
        $videoFormats = is_array($metadata['video_formats'] ?? null)
            ? $metadata['video_formats']
            : [];
        $audioFormats = is_array($metadata['audio_formats'] ?? null)
            ? $metadata['audio_formats']
            : [];
        $outputs = [];

        foreach (array_slice($videoFormats, 0, 6) as $format) {
            $resolution = is_string($format['resolution'] ?? null)
                ? $format['resolution']
                : 'Video';
            $codec = strtoupper((string) ($format['video_codec_family'] ?? 'other'));
            $merge = ($format['requires_merge'] ?? false)
                ? ' · separate audio required'
                : ' · includes audio';
            $outputs[] = [
                'id' => "youtube-video-{$format['format_id']}",
                'label' => strtoupper((string) $format['container'])." {$resolution}",
                'detail' => "{$codec}{$merge}",
                'available' => false,
            ];
        }

        foreach (array_slice($audioFormats, 0, 4) as $format) {
            $bitrate = is_int($format['bitrate_kbps'] ?? null)
                || is_float($format['bitrate_kbps'] ?? null)
                ? ' · '.round($format['bitrate_kbps']).' kbps'
                : '';
            $outputs[] = [
                'id' => "youtube-audio-{$format['format_id']}",
                'label' => strtoupper((string) $format['container']).' audio',
                'detail' => "{$format['audio_codec']}{$bitrate}",
                'available' => false,
            ];
        }

        $outputs[] = [
            'id' => 'youtube-mp3-plan',
            'label' => 'MP3',
            'detail' => 'Requires FFmpeg conversion in PR #9',
            'available' => false,
        ];

        if (is_string($metadata['thumbnail_url'] ?? null)) {
            $outputs[] = [
                'id' => 'youtube-thumbnail',
                'label' => 'Thumbnail',
                'detail' => 'Image delivery planned for PR #9',
                'available' => false,
            ];
        }

        return [
            'platform' => $recognition->platform->value,
            'platform_label' => $recognition->platform->label(),
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $metadata['title'],
            'thumbnail_url' => $metadata['thumbnail_url'] ?? null,
            'status' => 'ready',
            'metadata' => [
                'video_id' => $metadata['video_id'],
                'channel' => $metadata['author_name'] ?? null,
                'channel_id' => $metadata['author_handle'] ?? null,
                'duration_ms' => $metadata['duration_ms'] ?? null,
                'thumbnails' => $metadata['thumbnails'] ?? [],
            ],
            'video_formats' => $videoFormats,
            'audio_formats' => $audioFormats,
            'conversion_plans' => $metadata['conversion_plans'] ?? [],
            'assets' => [],
            'outputs' => $outputs,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function assetDetail(array $asset): string
    {
        $preferred = collect($asset['variants'] ?? [])->firstWhere('is_preferred', true);
        if (is_array($preferred) && is_string($preferred['quality_label'] ?? null)) {
            return "{$preferred['quality_label']} · delivery planned";
        }

        if (is_int($asset['width'] ?? null) && is_int($asset['height'] ?? null)) {
            return "{$asset['width']}×{$asset['height']} · delivery planned";
        }

        return 'Metadata ready · delivery planned';
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
