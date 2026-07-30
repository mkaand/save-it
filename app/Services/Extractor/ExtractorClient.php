<?php

namespace App\Services\Extractor;

use App\Enums\MediaPlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExtractorClient
{
    public function recognize(string $url): ExtractorRecognition
    {
        $requestId = (string) Str::uuid();

        try {
            $response = Http::baseUrl(rtrim((string) config('services.extractor.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout((float) config('services.extractor.connect_timeout_seconds'))
                ->timeout((float) config('services.extractor.timeout_seconds'))
                ->withOptions(['allow_redirects' => false])
                ->post('/v1/extract', [
                    'url' => $url,
                    'request_id' => $requestId,
                    'options' => ['metadata_only' => true],
                ]);
        } catch (ConnectionException) {
            throw new ExtractorException(
                'upstream_unavailable',
                503,
                $requestId,
                'The analysis service is temporarily unavailable.',
            );
        }

        return $this->mapResponse($response, $requestId);
    }

    private function mapResponse(Response $response, string $requestId): ExtractorRecognition
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->invalidContract($requestId);
        }

        if ($response->successful()) {
            return $this->recognitionFromData(
                data_get($payload, 'data'),
                $requestId,
            );
        }

        if ($response->status() === 501 && data_get($payload, 'error.code') === 'provider_not_implemented') {
            return $this->recognitionFromDetails(
                data_get($payload, 'error.details'),
                data_get($payload, 'error.request_id'),
                $requestId,
            );
        }

        if ($response->status() >= 400 && $response->status() < 500) {
            $code = data_get($payload, 'error.code');

            if (! is_string($code)) {
                throw $this->invalidContract($requestId);
            }

            throw new InvalidArgumentException($this->validationMessage(
                $code,
                data_get($payload, 'error.details.provider'),
            ));
        }

        if ($response->serverError()) {
            $code = data_get($payload, 'error.code');
            $provider = data_get($payload, 'error.details.provider');
            if (
                $provider === MediaPlatform::LinkedIn->value
                && is_string($code)
                && in_array($code, [
                    'upstream_blocked',
                    'rate_limited',
                    'parsing_failed',
                    'temporary_provider_error',
                    'provider_timeout',
                    'provider_response_too_large',
                ], true)
            ) {
                throw new ExtractorException(
                    $code,
                    $response->status() === 502 ? 502 : 503,
                    $requestId,
                    match ($code) {
                        'upstream_blocked' => 'LinkedIn temporarily blocked anonymous metadata access. Please try again later.',
                        'rate_limited' => 'LinkedIn is temporarily rate limiting anonymous metadata access.',
                        'parsing_failed' => 'LinkedIn did not expose reliable public post metadata.',
                        'provider_timeout' => 'LinkedIn did not respond before the analysis deadline.',
                        'provider_response_too_large' => 'LinkedIn returned more metadata than the service accepts.',
                        default => 'LinkedIn metadata is temporarily unavailable.',
                    },
                );
            }

            throw new ExtractorException(
                'upstream_unavailable',
                503,
                $requestId,
                'The analysis service is temporarily unavailable.',
            );
        }

        throw $this->invalidContract($requestId);
    }

    private function recognitionFromData(mixed $data, string $requestId): ExtractorRecognition
    {
        $provider = is_array($data) ? ($data['provider'] ?? null) : null;
        $variant = is_array($data) ? ($data['provider_variant'] ?? null) : null;
        $platform = is_string($provider)
            ? $this->readyPlatform($provider, is_string($variant) ? $variant : null)
            : null;
        $isYouTube = in_array(
            $platform,
            [MediaPlatform::YouTube, MediaPlatform::YouTubeShorts],
            true,
        );
        $expectedLabel = $isYouTube ? 'YouTube' : $platform?->label();

        if (
            ! is_array($data)
            || ! is_string($data['request_id'] ?? null)
            || ! hash_equals($requestId, $data['request_id'])
            || ! in_array($platform, [
                MediaPlatform::X,
                MediaPlatform::Instagram,
                MediaPlatform::YouTube,
                MediaPlatform::YouTubeShorts,
                MediaPlatform::LinkedIn,
            ], true)
            || ($data['provider_label'] ?? null) !== $expectedLabel
            || ! $this->validReadyVariant($platform, $variant)
            || ($data['status'] ?? null) !== 'ready'
            || ! is_string($data['media_type'] ?? null)
            || ! in_array($data['media_type'], [
                'video',
                'short_video',
                'image',
                'carousel',
                'mixed_media',
                'animated_gif',
                'reel',
                'text',
            ], true)
            || ! is_string($data['normalized_url'] ?? null)
            || ! $this->isSafeReadyUrl($platform, $data['normalized_url'], $variant)
            || ! is_array($data['metadata'] ?? null)
            || ! is_array($data['assets'] ?? null)
            || ($isYouTube ? $data['assets'] !== [] : (
                $platform === MediaPlatform::LinkedIn
                    ? ($data['media_type'] !== 'text' && $data['assets'] === [])
                    : $data['assets'] === []
            ))
            || count($data['assets']) > 20
            || ! is_array($data['capabilities'] ?? null)
        ) {
            throw $this->invalidContract($requestId);
        }

        $metadata = $isYouTube
            ? $this->youtubeMetadata($data['metadata'], $requestId)
            : (
                $platform === MediaPlatform::LinkedIn
                    ? $this->linkedinMetadata($data['metadata'], $requestId)
                    : $this->metadata($data['metadata'], $platform, $requestId)
            );
        $assets = [];

        foreach ($data['assets'] as $index => $asset) {
            $assets[] = $this->asset($asset, $index + 1, $platform, $requestId);
        }

        if (! $isYouTube && $metadata['media_count'] !== count($assets)) {
            throw $this->invalidContract($requestId);
        }

        $allowedCapabilities = $isYouTube
            ? ['metadata', 'thumbnails', 'video_formats', 'audio_formats', 'conversion_plans']
            : ['metadata', 'media_assets', 'video_variants', 'multiple_assets'];
        $capabilities = array_values(array_filter(
            $data['capabilities'],
            fn (mixed $capability): bool => is_string($capability)
                && in_array($capability, $allowedCapabilities, true),
        ));

        $maturity = $data['provider_maturity'] ?? null;
        $warnings = $data['warnings'] ?? null;
        if (
            ($platform === MediaPlatform::LinkedIn && $maturity !== 'stable')
            || ($platform !== MediaPlatform::LinkedIn && $maturity !== null)
            || ! is_array($warnings)
            || count($warnings) > 5
        ) {
            throw $this->invalidContract($requestId);
        }
        $normalizedWarnings = [];
        foreach ($warnings as $warning) {
            if (! is_string($warning) || $warning === '' || mb_strlen($warning) > 240) {
                throw $this->invalidContract($requestId);
            }
            $normalizedWarnings[] = $warning;
        }

        return new ExtractorRecognition(
            requestId: $requestId,
            platform: $platform,
            normalizedUrl: $data['normalized_url'],
            status: 'ready',
            mediaType: $data['media_type'],
            metadata: $metadata,
            assets: $assets,
            capabilities: array_values(array_unique($capabilities)),
            maturity: is_string($maturity) ? $maturity : null,
            warnings: $normalizedWarnings,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function linkedinMetadata(array $metadata, string $requestId): array
    {
        $mediaCount = $metadata['media_count'] ?? null;
        if (! is_int($mediaCount) || $mediaCount < 0 || $mediaCount > 20) {
            throw $this->invalidContract($requestId);
        }

        return [
            'post_id' => $this->nullableString($metadata['post_id'] ?? null, 300),
            'title' => $this->nullableString($metadata['title'] ?? null, 300),
            'description' => $this->nullableString($metadata['description'] ?? null, 500),
            'author_name' => $this->nullableString($metadata['author_name'] ?? null, 120),
            'author_handle' => $this->nullableString($metadata['author_handle'] ?? null, 120),
            'published_at' => $this->nullableString($metadata['published_at'] ?? null, 40),
            'thumbnail_url' => $this->safeAssetUrl(
                $metadata['thumbnail_url'] ?? null,
                MediaPlatform::LinkedIn,
                $requestId,
                true,
            ),
            'media_count' => $mediaCount,
            'duration_ms' => $this->nullablePositiveInt($metadata['duration_ms'] ?? null),
            'width' => $this->nullablePositiveInt($metadata['width'] ?? null),
            'height' => $this->nullablePositiveInt($metadata['height'] ?? null),
            'orientation' => in_array(
                $metadata['orientation'] ?? null,
                ['portrait', 'landscape', 'square'],
                true,
            ) ? $metadata['orientation'] : null,
            'captions_url' => $this->safeAssetUrl(
                $metadata['captions_url'] ?? null,
                MediaPlatform::LinkedIn,
                $requestId,
                true,
            ),
            'media_asset_id' => $this->nullableString(
                $metadata['media_asset_id'] ?? null,
                180,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadata(
        array $metadata,
        MediaPlatform $platform,
        string $requestId,
    ): array {
        $mediaCount = $metadata['media_count'] ?? null;

        if (! is_int($mediaCount) || $mediaCount < 1 || $mediaCount > 20) {
            throw $this->invalidContract($requestId);
        }

        return [
            'post_id' => $this->nullableString(
                $metadata['post_id'] ?? null,
                $platform === MediaPlatform::Instagram ? 64 : 20,
            ),
            'text' => $this->nullableString(
                $metadata[$platform === MediaPlatform::Instagram ? 'caption' : 'text'] ?? null,
                500,
            ),
            'author_name' => $this->nullableString($metadata['author_name'] ?? null, 120),
            'author_handle' => $this->nullableString(
                $metadata['author_handle'] ?? null,
                $platform === MediaPlatform::Instagram ? 30 : 15,
            ),
            'published_at' => $this->nullableString($metadata['published_at'] ?? null, 40),
            'thumbnail_url' => $this->safeAssetUrl(
                $metadata['thumbnail_url'] ?? null,
                $platform,
                $requestId,
                true,
            ),
            'media_count' => $mediaCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function youtubeMetadata(array $metadata, string $requestId): array
    {
        $videoId = $metadata['video_id'] ?? null;
        $title = $metadata['title'] ?? null;
        $videoFormats = $metadata['video_formats'] ?? null;
        $audioFormats = $metadata['audio_formats'] ?? null;
        $thumbnails = $metadata['thumbnails'] ?? null;
        $plans = $metadata['conversion_plans'] ?? null;

        if (
            ! is_string($videoId)
            || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1
            || ! is_string($title)
            || $title === ''
            || mb_strlen($title) > 300
            || ! is_array($videoFormats)
            || count($videoFormats) > 30
            || ! is_array($audioFormats)
            || count($audioFormats) > 20
            || ($videoFormats === [] && $audioFormats === [])
            || ! is_array($thumbnails)
            || count($thumbnails) > 8
            || ! is_array($plans)
            || count($plans) !== 1
        ) {
            throw $this->invalidContract($requestId);
        }

        $normalizedThumbnails = array_map(
            fn (mixed $thumbnail): array => $this->youtubeThumbnail($thumbnail, $requestId),
            $thumbnails,
        );
        $normalizedVideo = array_map(
            fn (mixed $format): array => $this->youtubeVideoFormat($format, $requestId),
            $videoFormats,
        );
        $normalizedAudio = array_map(
            fn (mixed $format): array => $this->youtubeAudioFormat($format, $requestId),
            $audioFormats,
        );
        $plan = $plans[0] ?? null;

        if (
            ! is_array($plan)
            || ($plan['id'] ?? null) !== 'mp3'
            || ($plan['label'] ?? null) !== 'MP3'
            || ($plan['source'] ?? null) !== 'audio_format'
            || ($plan['requires_ffmpeg'] ?? null) !== true
            || ($plan['available'] ?? null) !== false
        ) {
            throw $this->invalidContract($requestId);
        }

        return [
            'video_id' => $videoId,
            'title' => $title,
            'author_name' => $this->nullableString($metadata['author_name'] ?? null, 120),
            'author_handle' => $this->nullableString($metadata['author_handle'] ?? null, 120),
            'duration_ms' => $this->nullablePositiveInt($metadata['duration_ms'] ?? null),
            'thumbnail_url' => $this->safeAssetUrl(
                $metadata['thumbnail_url'] ?? null,
                MediaPlatform::YouTube,
                $requestId,
                true,
            ),
            'thumbnails' => $normalizedThumbnails,
            'video_formats' => $normalizedVideo,
            'audio_formats' => $normalizedAudio,
            'conversion_plans' => [[
                'id' => 'mp3',
                'label' => 'MP3',
                'source' => 'audio_format',
                'requires_ffmpeg' => true,
                'available' => false,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeThumbnail(mixed $thumbnail, string $requestId): array
    {
        if (! is_array($thumbnail)) {
            throw $this->invalidContract($requestId);
        }

        return [
            'url' => $this->safeAssetUrl(
                $thumbnail['url'] ?? null,
                MediaPlatform::YouTube,
                $requestId,
            ),
            'width' => $this->nullablePositiveInt($thumbnail['width'] ?? null),
            'height' => $this->nullablePositiveInt($thumbnail['height'] ?? null),
            'preference' => is_int($thumbnail['preference'] ?? null)
                ? $thumbnail['preference']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeVideoFormat(mixed $format, string $requestId): array
    {
        if (
            ! is_array($format)
            || ! $this->validFormatId($format['format_id'] ?? null)
            || ! in_array($format['container'] ?? null, ['mp4', 'webm'], true)
            || ! is_string($format['video_codec'] ?? null)
            || ! in_array($format['video_codec_family'] ?? null, ['h264', 'h265', 'other'], true)
            || ! is_bool($format['has_audio'] ?? null)
            || ! is_bool($format['requires_merge'] ?? null)
            || ! is_int($format['preference'] ?? null)
            || $format['preference'] < 0
            || $format['preference'] > 3
        ) {
            throw $this->invalidContract($requestId);
        }

        return [
            'format_id' => $format['format_id'],
            'container' => $format['container'],
            'video_codec' => $this->nullableString($format['video_codec'], 80),
            'video_codec_family' => $format['video_codec_family'],
            'audio_codec' => $this->nullableString($format['audio_codec'] ?? null, 80),
            'width' => $this->nullablePositiveInt($format['width'] ?? null),
            'height' => $this->nullablePositiveInt($format['height'] ?? null),
            'resolution' => $this->nullableString($format['resolution'] ?? null, 40),
            'fps' => $this->nullablePositiveNumber($format['fps'] ?? null),
            'bitrate_kbps' => $this->nullablePositiveNumber($format['bitrate_kbps'] ?? null),
            'estimated_filesize' => $this->nullablePositiveInt(
                $format['estimated_filesize'] ?? null,
            ),
            'has_audio' => $format['has_audio'],
            'requires_merge' => $format['requires_merge'],
            'preference' => $format['preference'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function youtubeAudioFormat(mixed $format, string $requestId): array
    {
        if (
            ! is_array($format)
            || ! $this->validFormatId($format['format_id'] ?? null)
            || ! in_array($format['container'] ?? null, ['m4a', 'webm'], true)
            || ! is_string($format['audio_codec'] ?? null)
            || ! is_int($format['preference'] ?? null)
            || ! in_array($format['preference'], [0, 1], true)
        ) {
            throw $this->invalidContract($requestId);
        }

        return [
            'format_id' => $format['format_id'],
            'container' => $format['container'],
            'audio_codec' => $this->nullableString($format['audio_codec'], 80),
            'bitrate_kbps' => $this->nullablePositiveNumber($format['bitrate_kbps'] ?? null),
            'sample_rate_hz' => $this->nullablePositiveInt($format['sample_rate_hz'] ?? null),
            'estimated_filesize' => $this->nullablePositiveInt(
                $format['estimated_filesize'] ?? null,
            ),
            'language' => $this->nullableString($format['language'] ?? null, 32),
            'preference' => $format['preference'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(
        mixed $asset,
        int $expectedOrder,
        MediaPlatform $platform,
        string $requestId,
    ): array {
        if (
            ! is_array($asset)
            || ! is_string($asset['id'] ?? null)
            || preg_match('/^asset-[1-9][0-9]*$/', $asset['id']) !== 1
            || ($asset['order'] ?? null) !== $expectedOrder
            || ! in_array($asset['type'] ?? null, ['video', 'image', 'animated_gif'], true)
            || ! in_array($asset['role'] ?? null, ['primary', 'gallery'], true)
            || ! is_array($asset['variants'] ?? null)
            || count($asset['variants']) > 12
        ) {
            throw $this->invalidContract($requestId);
        }

        $variants = [];
        foreach ($asset['variants'] as $variant) {
            $variants[] = $this->variant($variant, $platform, $requestId);
        }

        return [
            'id' => $asset['id'],
            'order' => $expectedOrder,
            'type' => $asset['type'],
            'role' => $asset['role'],
            'url' => $this->safeAssetUrl($asset['url'] ?? null, $platform, $requestId),
            'thumbnail_url' => $this->safeAssetUrl(
                $asset['thumbnail_url'] ?? null,
                $platform,
                $requestId,
                true,
            ),
            'mime_type' => $this->nullableString($asset['mime_type'] ?? null, 64),
            'width' => $this->nullablePositiveInt($asset['width'] ?? null),
            'height' => $this->nullablePositiveInt($asset['height'] ?? null),
            'duration_ms' => $this->nullablePositiveInt($asset['duration_ms'] ?? null),
            'alt_text' => $this->nullableString($asset['alt_text'] ?? null, 500),
            'variants' => $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(
        mixed $variant,
        MediaPlatform $platform,
        string $requestId,
    ): array {
        if (
            ! is_array($variant)
            || ! in_array($variant['mime_type'] ?? null, ['video/mp4', 'application/x-mpegURL'], true)
            || ! in_array($variant['protocol'] ?? null, ['https', 'hls'], true)
            || ! is_bool($variant['is_preferred'] ?? null)
        ) {
            throw $this->invalidContract($requestId);
        }

        return [
            'url' => $this->safeAssetUrl($variant['url'] ?? null, $platform, $requestId),
            'mime_type' => $variant['mime_type'],
            'protocol' => $variant['protocol'],
            'bitrate' => $this->nullablePositiveInt($variant['bitrate'] ?? null),
            'width' => $this->nullablePositiveInt($variant['width'] ?? null),
            'height' => $this->nullablePositiveInt($variant['height'] ?? null),
            'fps' => $this->nullablePositiveInt($variant['fps'] ?? null),
            'container' => in_array($variant['container'] ?? null, ['mp4', 'hls'], true)
                ? $variant['container']
                : null,
            'quality_label' => $this->nullableString($variant['quality_label'] ?? null, 40),
            'filesize' => $this->nullablePositiveInt($variant['filesize'] ?? null),
            'is_preferred' => $variant['is_preferred'],
        ];
    }

    private function isSafeXPostUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'x.com'
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && preg_match('#^/[A-Za-z0-9_]{1,15}/status/[0-9]{1,20}$#', (string) ($parts['path'] ?? '')) === 1;
    }

    private function isSafeInstagramUrl(string $url, mixed $variant): bool
    {
        $parts = parse_url($url);
        $kind = $variant === 'reel' ? 'reel' : 'p';

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'www.instagram.com'
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && preg_match("#^/{$kind}/[A-Za-z0-9_-]{5,64}/$#", (string) ($parts['path'] ?? '')) === 1;
    }

    private function isSafeLinkedInUrl(string $url, mixed $variant): bool
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $postSlug = str_starts_with($path, '/posts/') && str_ends_with($path, '/')
            ? substr($path, 7, -1)
            : '';
        $validPath = $variant === 'activity'
            ? preg_match('#^/feed/update/urn:li:activity:[0-9]{6,30}/$#', $path) === 1
            : ($variant === 'post'
                && strlen($postSlug) >= 3
                && strlen($postSlug) <= 900
                && preg_match('#^(?:[A-Za-z0-9._~-]|%[A-Fa-f0-9]{2})+$#', $postSlug) === 1);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'www.linkedin.com'
            && $validPath
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    private function validReadyVariant(MediaPlatform $platform, mixed $variant): bool
    {
        return match ($platform) {
            MediaPlatform::X => $variant === null,
            MediaPlatform::Instagram => in_array($variant, ['post', 'reel'], true),
            MediaPlatform::YouTube => $variant === 'video',
            MediaPlatform::YouTubeShorts => $variant === 'shorts',
            MediaPlatform::LinkedIn => in_array($variant, ['post', 'activity'], true),
            default => false,
        };
    }

    private function isSafeReadyUrl(
        MediaPlatform $platform,
        string $url,
        mixed $variant,
    ): bool {
        return match ($platform) {
            MediaPlatform::X => $this->isSafeXPostUrl($url),
            MediaPlatform::Instagram => $this->isSafeInstagramUrl($url, $variant),
            MediaPlatform::LinkedIn => $this->isSafeLinkedInUrl($url, $variant),
            MediaPlatform::YouTube, MediaPlatform::YouTubeShorts => $this->isSafeYouTubeUrl(
                $url,
                $platform,
            ),
            default => false,
        };
    }

    private function isSafeYouTubeUrl(string $url, MediaPlatform $platform): bool
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $validPath = $platform === MediaPlatform::YouTubeShorts
            ? preg_match('#^/shorts/[A-Za-z0-9_-]{11}$#', $path) === 1 && $query === ''
            : $path === '/watch' && preg_match('/^v=[A-Za-z0-9_-]{11}$/', $query) === 1;

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'www.youtube.com'
            && $validPath
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    private function safeAssetUrl(
        mixed $url,
        MediaPlatform $platform,
        string $requestId,
        bool $nullable = false,
    ): ?string {
        if ($nullable && $url === null) {
            return null;
        }

        if (! is_string($url)) {
            throw $this->invalidContract($requestId);
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHost = $platform === MediaPlatform::X
            ? in_array($host, ['pbs.twimg.com', 'video.twimg.com'], true)
            : (
                $platform === MediaPlatform::Instagram
                    ? str_ends_with($host, '.cdninstagram.com') && $host !== 'cdninstagram.com'
                    : (
                        $platform === MediaPlatform::LinkedIn
                            ? ($host !== 'licdn.com' && str_ends_with($host, '.licdn.com'))
                            : in_array($host, ['i.ytimg.com', 'img.youtube.com'], true)
                    )
            );
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! $allowedHost
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            throw $this->invalidContract($requestId);
        }

        return $url;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && mb_strlen($value) <= $maxLength ? $value : null;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function nullablePositiveNumber(mixed $value): float|int|null
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? $value : null;
    }

    private function validFormatId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9._+-]{1,100}$/', $value) === 1;
    }

    private function recognitionFromDetails(
        mixed $details,
        mixed $responseRequestId,
        string $requestId,
    ): ExtractorRecognition {
        if (
            ! is_array($details)
            || ! is_string($responseRequestId)
            || ! hash_equals($requestId, $responseRequestId)
            || ! is_string($details['provider'] ?? null)
            || ! is_string($details['normalized_url'] ?? null)
            || ($details['status'] ?? null) !== 'not_implemented'
            || ! array_key_exists('metadata', $details)
            || $details['metadata'] !== null
            || ($details['assets'] ?? null) !== []
        ) {
            throw $this->invalidContract($requestId);
        }

        $platform = $this->platform(
            $details['provider'],
            is_string($details['provider_variant'] ?? null)
                ? $details['provider_variant']
                : null,
        );

        if ($platform === null || ! $this->isSafeNormalizedUrl($details['normalized_url'])) {
            throw $this->invalidContract($requestId);
        }

        return new ExtractorRecognition(
            requestId: $requestId,
            platform: $platform,
            normalizedUrl: $details['normalized_url'],
        );
    }

    private function platform(string $provider, ?string $variant): ?MediaPlatform
    {
        if ($provider === MediaPlatform::YouTube->value && $variant === 'shorts') {
            return MediaPlatform::YouTubeShorts;
        }

        if ($variant !== null) {
            return null;
        }

        return MediaPlatform::tryFrom($provider);
    }

    private function readyPlatform(string $provider, ?string $variant): ?MediaPlatform
    {
        if ($provider === MediaPlatform::YouTube->value) {
            return $variant === 'shorts'
                ? MediaPlatform::YouTubeShorts
                : ($variant === 'video' ? MediaPlatform::YouTube : null);
        }

        if ($provider === MediaPlatform::Instagram->value) {
            return in_array($variant, ['post', 'reel'], true)
                ? MediaPlatform::Instagram
                : null;
        }

        if ($provider === MediaPlatform::LinkedIn->value) {
            return in_array($variant, ['post', 'activity'], true)
                ? MediaPlatform::LinkedIn
                : null;
        }

        return $variant === null ? MediaPlatform::tryFrom($provider) : null;
    }

    private function isSafeNormalizedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    private function validationMessage(string $code, mixed $provider = null): string
    {
        return match ($code) {
            'unsupported_scheme' => 'Only HTTP and HTTPS URLs are supported.',
            'embedded_credentials' => 'URLs containing embedded credentials are not allowed.',
            'disallowed_port' => 'URLs with custom ports are not supported.',
            'unsupported_host' => 'This media host is not supported yet.',
            'url_too_long' => 'The media URL is too long.',
            'invalid_x_post_url' => 'Enter a valid X post URL.',
            'invalid_instagram_media_url' => 'Enter a valid Instagram post or reel URL.',
            'invalid_youtube_video_url' => 'Enter a valid YouTube video or Shorts URL.',
            'invalid_linkedin_post_url' => 'LinkedIn supports public post URLs only.',
            'linkedin_short_url_not_supported' => 'LinkedIn short links are not supported. Use the full public post URL.',
            'playlist_not_supported' => 'YouTube playlists are not supported. Submit a single video URL.',
            'live_not_supported' => 'YouTube live and scheduled live videos are not supported.',
            'authentication_required' => $provider === MediaPlatform::LinkedIn->value
                ? 'This LinkedIn post requires signing in and cannot be analyzed anonymously.'
                : 'This video is private or requires authentication.',
            'age_restricted' => 'Age-restricted YouTube videos are not supported.',
            'drm_protected' => 'DRM-protected YouTube media is not supported.',
            'video_unavailable' => 'This YouTube video is unavailable.',
            'no_media' => match ($provider) {
                MediaPlatform::Instagram->value => 'This public Instagram post does not contain extractable media.',
                MediaPlatform::YouTube->value => 'This YouTube video does not expose supported media formats.',
                MediaPlatform::LinkedIn->value => 'No downloadable media metadata was found in this public LinkedIn post.',
                default => 'This public X post does not contain directly attached media.',
            },
            'post_unavailable' => 'This post is unavailable, private, or requires authentication.',
            'private_content', 'unavailable_content' => 'This LinkedIn post is private or unavailable.',
            'upstream_blocked' => 'LinkedIn temporarily blocked anonymous metadata access. Please try again later.',
            'rate_limited' => 'LinkedIn is temporarily rate limiting anonymous metadata access.',
            'parsing_failed' => 'LinkedIn did not expose reliable public post metadata.',
            default => 'Enter a valid media URL.',
        };
    }

    private function invalidContract(string $requestId): ExtractorException
    {
        return new ExtractorException(
            'upstream_invalid_response',
            502,
            $requestId,
            'The analysis service returned an invalid response.',
        );
    }
}
