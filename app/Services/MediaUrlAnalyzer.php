<?php

namespace App\Services;

use App\Enums\MediaPlatform;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Extractor\ExtractorClient;
use App\Services\Extractor\ExtractorRecognition;
use App\Services\Previews\RecentPreviewStore;

final class MediaUrlAnalyzer
{
    public function __construct(
        private readonly ExtractorClient $extractor,
        private readonly DownloadAssetStore $downloads,
        private readonly RecentPreviewStore $previews,
    ) {}

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
        $publicAssets = $this->publicAssets($recognition, $title);
        $publicThumbnail = $publicAssets[0]['preview_url'] ?? null;
        $metadata['thumbnail_url'] = $publicThumbnail;

        return [
            'platform' => $recognition->platform->value,
            'platform_label' => $recognition->platform->label(),
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $title,
            'thumbnail_url' => $publicThumbnail,
            'status' => 'ready',
            'metadata' => $metadata,
            'assets' => $publicAssets,
            'capabilities' => $recognition->capabilities,
            'provider_maturity' => $recognition->maturity,
            'warnings' => $recognition->warnings,
            'outputs' => $this->assetOutputs($recognition, $title),
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

        $publicAssets = $this->publicAssets($recognition, $title);
        $publicThumbnail = $publicAssets[0]['preview_url'] ?? null;
        unset($metadata['captions_url']);
        $metadata['thumbnail_url'] = $publicThumbnail;

        $outputs = $this->assetOutputs($recognition, $title);
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
            'provider_maturity' => $recognition->maturity,
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $title,
            'thumbnail_url' => $publicThumbnail,
            'status' => 'ready',
            'metadata' => $metadata,
            'assets' => $publicAssets,
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
            if (($format['requires_merge'] ?? false) && $format['container'] !== 'mp4') {
                continue;
            }
            $resolution = is_string($format['resolution'] ?? null)
                ? $format['resolution']
                : 'Video';
            $codec = match ($format['video_codec_family'] ?? 'other') {
                'h264' => 'H.264',
                'h265' => 'H.265',
                default => 'Compatible video',
            };
            $merge = ($format['requires_merge'] ?? false)
                ? ' · video and audio will be merged'
                : (($format['has_audio'] ?? false) ? ' · audio included' : ' · video only');
            $mode = ($format['requires_merge'] ?? false) ? 'youtube_merge' : 'youtube_direct';
            $payload = [
                'version' => 1,
                'mode' => $mode,
                'provider' => 'youtube',
                'normalized_url' => $recognition->normalizedUrl,
                'format_id' => $format['format_id'],
                'mime_type' => $format['container'] === 'webm' ? 'video/webm' : 'video/mp4',
                'filename' => $this->downloadFilename(
                    (string) $metadata['title'],
                    $format['container'] === 'webm' ? 'video/webm' : 'video/mp4',
                    1,
                ),
                'expected_size' => $format['estimated_filesize'] ?? null,
            ];
            if ($mode === 'youtube_merge') {
                $audio = collect($audioFormats)->firstWhere('container', 'm4a')
                    ?? ($audioFormats[0] ?? null);
                if (! is_array($audio)) {
                    continue;
                }
                $payload['sources'] = [
                    [
                        'mode' => 'youtube_direct',
                        'provider' => 'youtube',
                        'normalized_url' => $recognition->normalizedUrl,
                        'format_id' => $format['format_id'],
                    ],
                    [
                        'mode' => 'youtube_direct',
                        'provider' => 'youtube',
                        'normalized_url' => $recognition->normalizedUrl,
                        'format_id' => $audio['format_id'],
                    ],
                ];
            }
            $token = $this->downloads->issue($payload);
            $outputs[] = [
                'id' => "youtube-video-{$format['format_id']}",
                'label' => strtoupper((string) $format['container'])." {$resolution}",
                'detail' => "{$codec}{$merge}",
                'available' => true,
                ...$this->delivery($mode, $token),
            ];
        }

        foreach (array_slice($audioFormats, 0, 4) as $format) {
            $bitrate = is_int($format['bitrate_kbps'] ?? null)
                || is_float($format['bitrate_kbps'] ?? null)
                ? ' · '.round($format['bitrate_kbps']).' kbps'
                : '';
            $token = $this->downloads->issue([
                'version' => 1,
                'mode' => 'youtube_direct',
                'provider' => 'youtube',
                'normalized_url' => $recognition->normalizedUrl,
                'format_id' => $format['format_id'],
                'mime_type' => $format['container'] === 'm4a' ? 'audio/mp4' : 'audio/webm',
                'filename' => $this->downloadFilename(
                    (string) $metadata['title'],
                    $format['container'] === 'm4a' ? 'audio/m4a' : 'audio/webm',
                    1,
                ),
                'expected_size' => $format['estimated_filesize'] ?? null,
            ]);
            $outputs[] = [
                'id' => "youtube-audio-{$format['format_id']}",
                'label' => strtoupper((string) $format['container']).' audio',
                'detail' => "{$format['audio_codec']}{$bitrate}",
                'available' => true,
                ...$this->delivery('youtube_direct', $token),
            ];
        }

        $mp3Source = collect($audioFormats)->firstWhere('container', 'm4a')
            ?? ($audioFormats[0] ?? null);
        if (is_array($mp3Source)) {
            foreach ([128, 192, 256, 320] as $bitrate) {
                $token = $this->downloads->issue([
                    'version' => 1,
                    'mode' => 'youtube_mp3',
                    'provider' => 'youtube',
                    'filename' => $this->downloadFilename(
                        (string) $metadata['title'],
                        'audio/mpeg',
                        1,
                    ),
                    'bitrate_kbps' => $bitrate,
                    'sources' => [[
                        'mode' => 'youtube_direct',
                        'provider' => 'youtube',
                        'normalized_url' => $recognition->normalizedUrl,
                        'format_id' => $mp3Source['format_id'],
                    ]],
                ]);
                $outputs[] = [
                    'id' => "youtube-mp3-{$bitrate}",
                    'label' => "MP3 {$bitrate} kbps",
                    'detail' => 'Prepared with FFmpeg',
                    'available' => true,
                    ...$this->delivery('youtube_mp3', $token),
                ];
            }
        }

        if (is_string($metadata['thumbnail_url'] ?? null)) {
            $token = $this->downloads->issue([
                'version' => 1,
                'mode' => 'proxy',
                'provider' => 'youtube',
                'asset_id' => 'thumbnail',
                'upstream_url' => $metadata['thumbnail_url'],
                'mime_type' => 'image/jpeg',
                'filename' => $this->downloadFilename(
                    (string) $metadata['title'].' thumbnail',
                    'image/jpeg',
                    1,
                ),
                'expected_size' => null,
            ]);
            $outputs[] = [
                'id' => 'youtube-thumbnail',
                'label' => 'Thumbnail',
                'detail' => 'Original preview image',
                'available' => true,
                ...$this->delivery('proxy', $token),
            ];
        }
        $thumbnailUrl = null;
        if (is_string($metadata['thumbnail_url'] ?? null)) {
            $thumbnailUrl = $this->previewReference([
                'version' => 1,
                'mode' => 'proxy',
                'provider' => 'youtube',
                'asset_id' => 'thumbnail-preview',
                'upstream_url' => $metadata['thumbnail_url'],
                'mime_type' => 'image/jpeg',
                'filename' => $this->downloadFilename(
                    (string) $metadata['title'].' thumbnail',
                    'image/jpeg',
                    1,
                ),
                'disposition' => 'inline',
                'expected_size' => null,
            ]);
        }

        return [
            'platform' => $recognition->platform->value,
            'platform_label' => $recognition->platform->label(),
            'media_type' => $recognition->mediaType,
            'url' => $recognition->normalizedUrl,
            'title' => $metadata['title'],
            'thumbnail_url' => $thumbnailUrl,
            'status' => 'ready',
            'metadata' => [
                'video_id' => $metadata['video_id'],
                'channel' => $metadata['author_name'] ?? null,
                'channel_id' => $metadata['author_handle'] ?? null,
                'duration_ms' => $metadata['duration_ms'] ?? null,
                'thumbnails' => array_map(
                    fn (array $thumbnail): array => [
                        'width' => $thumbnail['width'] ?? null,
                        'height' => $thumbnail['height'] ?? null,
                    ],
                    $metadata['thumbnails'] ?? [],
                ),
            ],
            'video_formats' => $videoFormats,
            'audio_formats' => $audioFormats,
            'conversion_plans' => [[
                'id' => 'mp3',
                'label' => 'MP3',
                'source' => 'audio_format',
                'requires_ffmpeg' => true,
                'available' => $mp3Source !== null,
                'bitrates_kbps' => [128, 192, 256, 320],
            ]],
            'assets' => [],
            'outputs' => $outputs,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function assetDetail(array $asset, array $source): string
    {
        if (is_string($source['quality_label'] ?? null) && $source['quality_label'] !== '') {
            return "{$source['quality_label']} · secure proxy delivery";
        }

        if (is_int($source['width'] ?? null) && is_int($source['height'] ?? null)) {
            return "{$source['width']}×{$source['height']} · secure proxy delivery";
        }

        if (is_int($asset['width'] ?? null) && is_int($asset['height'] ?? null)) {
            return "{$asset['width']}×{$asset['height']} · secure proxy delivery";
        }

        return 'Secure proxy delivery';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assetOutputs(ExtractorRecognition $recognition, string $title): array
    {
        $outputs = [];
        $assetCount = count($recognition->assets);
        foreach ($recognition->assets as $asset) {
            $variants = is_array($asset['variants'] ?? null) ? $asset['variants'] : [];
            $sources = $variants !== []
                ? $variants
                : [[
                    'url' => $asset['url'] ?? null,
                    'mime_type' => $asset['mime_type'] ?? null,
                    'quality_label' => null,
                    'filesize' => null,
                ]];

            foreach ($sources as $variantIndex => $source) {
                $url = $source['url'] ?? null;
                if (! is_string($url) || $url === '') {
                    continue;
                }
                $mime = is_string($source['mime_type'] ?? null)
                    ? $source['mime_type']
                    : (is_string($asset['mime_type'] ?? null) ? $asset['mime_type'] : null);
                if ($mime === 'application/x-mpegURL') {
                    continue;
                }
                $label = $assetCount > 1
                    ? sprintf(
                        '%s %d of %d',
                        match ($asset['type']) {
                            'image' => 'Image',
                            'animated_gif' => 'Animated GIF video',
                            default => 'Video',
                        },
                        $asset['order'],
                        $assetCount,
                    )
                    : (is_string($source['quality_label'] ?? null)
                        ? $source['quality_label']
                        : match ($asset['type']) {
                            'image' => 'Original image',
                            'animated_gif' => 'Animated GIF video',
                            default => 'Video',
                        });
                $token = $this->downloads->issue([
                    'version' => 1,
                    'mode' => 'proxy',
                    'provider' => $recognition->platform->value,
                    'asset_id' => $asset['id'],
                    'upstream_url' => $url,
                    'mime_type' => $mime,
                    'filename' => $this->downloadFilename($title, $mime, $variantIndex + 1),
                    'expected_size' => is_int($source['filesize'] ?? null)
                        ? $source['filesize']
                        : null,
                ]);
                $outputs[] = [
                    'id' => $asset['id'].'-'.($variantIndex + 1),
                    'label' => $label,
                    'detail' => $this->assetDetail($asset, $source),
                    'available' => true,
                    'asset_id' => $asset['id'],
                    'asset_order' => $asset['order'],
                    'asset_type' => $asset['type'],
                    'delivery' => 'proxy',
                    'download_url' => route('api.downloads.show', ['token' => $token], false),
                    'expires_in' => (int) config('services.downloads.token_ttl_seconds'),
                ];
            }
        }

        if (count($recognition->assets) > 1) {
            $sources = [];
            foreach ($recognition->assets as $index => $asset) {
                $variants = collect($asset['variants'] ?? []);
                $preferred = $variants->first(
                    fn (array $variant): bool => ($variant['is_preferred'] ?? false)
                        && ($variant['mime_type'] ?? null) === 'video/mp4',
                ) ?? $variants->firstWhere('mime_type', 'video/mp4');
                $url = is_array($preferred) ? ($preferred['url'] ?? null) : ($asset['url'] ?? null);
                if (! is_string($url)) {
                    continue;
                }
                $mime = is_array($preferred)
                    ? ($preferred['mime_type'] ?? $asset['mime_type'] ?? null)
                    : ($asset['mime_type'] ?? null);
                $sources[] = [
                    'provider' => $recognition->platform->value,
                    'upstream_url' => $url,
                    'mime_type' => $mime,
                    'filename' => $this->downloadFilename($title, is_string($mime) ? $mime : null, $index + 1),
                ];
            }
            if (count($sources) === count($recognition->assets)) {
                $token = $this->downloads->issue([
                    'version' => 1,
                    'mode' => 'zip',
                    'provider' => $recognition->platform->value,
                    'filename' => $this->downloadFilename($title, 'application/zip', 1),
                    'sources' => $sources,
                ]);
                $outputs[] = [
                    'id' => 'download-all',
                    'label' => 'Download all as ZIP',
                    'detail' => count($sources).' media files · prepared securely',
                    'available' => true,
                    ...$this->delivery('zip', $token),
                ];
            }
        }

        return $outputs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicAssets(ExtractorRecognition $recognition, string $title): array
    {
        return array_map(function (array $asset) use ($recognition, $title): array {
            $previewSource = $asset['thumbnail_url'] ?? (
                $asset['type'] === 'image' ? ($asset['url'] ?? null) : null
            );
            $previewUrl = null;
            if (is_string($previewSource) && $previewSource !== '') {
                $previewUrl = $this->previewReference([
                    'version' => 1,
                    'mode' => 'proxy',
                    'provider' => $recognition->platform->value,
                    'asset_id' => $asset['id'].'-preview',
                    'upstream_url' => $previewSource,
                    'mime_type' => is_string($asset['thumbnail_url'] ?? null)
                        ? 'image/jpeg'
                        : ($asset['mime_type'] ?? 'image/jpeg'),
                    'filename' => $this->downloadFilename($title.' preview', 'image/jpeg', 1),
                    'disposition' => 'inline',
                    'expected_size' => null,
                ]);
            }

            return [
                'id' => $asset['id'],
                'order' => $asset['order'],
                'type' => $asset['type'],
                'role' => $asset['role'],
                'preview_url' => $previewUrl,
                'mime_type' => $asset['mime_type'],
                'width' => $asset['width'],
                'height' => $asset['height'],
                'duration_ms' => $asset['duration_ms'],
                'alt_text' => $asset['alt_text'],
                'variants' => array_map(
                    fn (array $variant): array => [
                        'mime_type' => $variant['mime_type'],
                        'protocol' => $variant['protocol'],
                        'bitrate' => $variant['bitrate'],
                        'width' => $variant['width'],
                        'height' => $variant['height'],
                        'fps' => $variant['fps'] ?? null,
                        'container' => $variant['container'] ?? null,
                        'quality_label' => $variant['quality_label'],
                        'filesize' => $variant['filesize'] ?? null,
                        'is_preferred' => $variant['is_preferred'],
                    ],
                    $asset['variants'],
                ),
            ];
        }, $recognition->assets);
    }

    /** @param array<string, mixed> $asset */
    private function previewReference(array $asset): ?string
    {
        try {
            return route('api.previews.show', ['preview' => $this->previews->issue($asset)], false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function delivery(string $mode, string $token): array
    {
        if (in_array($mode, ['youtube_merge', 'youtube_mp3', 'zip'], true)) {
            return [
                'delivery' => 'job',
                'job_url' => route('api.download-jobs.store', absolute: false),
                'job_token' => $token,
                'expires_in' => (int) config('services.downloads.token_ttl_seconds'),
            ];
        }

        return [
            'delivery' => 'proxy',
            'download_url' => route('api.downloads.show', ['token' => $token], false),
            'expires_in' => (int) config('services.downloads.token_ttl_seconds'),
        ];
    }

    private function downloadFilename(string $title, ?string $mime, int $index): string
    {
        $base = preg_replace('/[^\pL\pN._ -]+/u', '-', $title) ?: 'media';
        $base = mb_substr(trim($base, " .-\t\n\r\0\x0B"), 0, 120) ?: 'media';
        $extension = match ($mime) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            default => 'jpg',
        };

        return "{$base}-{$index}.{$extension}";
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
