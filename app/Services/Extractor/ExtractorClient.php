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

            throw new InvalidArgumentException($this->validationMessage($code));
        }

        if ($response->serverError()) {
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
        if (
            ! is_array($data)
            || ! is_string($data['request_id'] ?? null)
            || ! hash_equals($requestId, $data['request_id'])
            || ($data['provider'] ?? null) !== MediaPlatform::X->value
            || ($data['provider_label'] ?? null) !== 'X'
            || ($data['provider_variant'] ?? null) !== null
            || ($data['status'] ?? null) !== 'ready'
            || ! is_string($data['media_type'] ?? null)
            || ! in_array($data['media_type'], ['video', 'image', 'carousel', 'mixed_media', 'animated_gif'], true)
            || ! is_string($data['normalized_url'] ?? null)
            || ! $this->isSafeXPostUrl($data['normalized_url'])
            || ! is_array($data['metadata'] ?? null)
            || ! is_array($data['assets'] ?? null)
            || $data['assets'] === []
            || count($data['assets']) > 20
            || ! is_array($data['capabilities'] ?? null)
        ) {
            throw $this->invalidContract($requestId);
        }

        $metadata = $this->metadata($data['metadata'], $requestId);
        $assets = [];

        foreach ($data['assets'] as $index => $asset) {
            $assets[] = $this->asset($asset, $index + 1, $requestId);
        }

        if ($metadata['media_count'] !== count($assets)) {
            throw $this->invalidContract($requestId);
        }

        $capabilities = array_values(array_filter(
            $data['capabilities'],
            fn (mixed $capability): bool => is_string($capability)
                && in_array($capability, ['metadata', 'media_assets', 'video_variants', 'multiple_assets'], true),
        ));

        return new ExtractorRecognition(
            requestId: $requestId,
            platform: MediaPlatform::X,
            normalizedUrl: $data['normalized_url'],
            status: 'ready',
            mediaType: $data['media_type'],
            metadata: $metadata,
            assets: $assets,
            capabilities: array_values(array_unique($capabilities)),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadata(array $metadata, string $requestId): array
    {
        $mediaCount = $metadata['media_count'] ?? null;

        if (! is_int($mediaCount) || $mediaCount < 1 || $mediaCount > 20) {
            throw $this->invalidContract($requestId);
        }

        return [
            'post_id' => $this->nullableString($metadata['post_id'] ?? null, 20),
            'text' => $this->nullableString($metadata['text'] ?? null, 500),
            'author_name' => $this->nullableString($metadata['author_name'] ?? null, 120),
            'author_handle' => $this->nullableString($metadata['author_handle'] ?? null, 15),
            'published_at' => $this->nullableString($metadata['published_at'] ?? null, 40),
            'thumbnail_url' => $this->safeAssetUrl(
                $metadata['thumbnail_url'] ?? null,
                $requestId,
                true,
            ),
            'media_count' => $mediaCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(mixed $asset, int $expectedOrder, string $requestId): array
    {
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
            $variants[] = $this->variant($variant, $requestId);
        }

        return [
            'id' => $asset['id'],
            'order' => $expectedOrder,
            'type' => $asset['type'],
            'role' => $asset['role'],
            'url' => $this->safeAssetUrl($asset['url'] ?? null, $requestId),
            'thumbnail_url' => $this->safeAssetUrl(
                $asset['thumbnail_url'] ?? null,
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
    private function variant(mixed $variant, string $requestId): array
    {
        if (
            ! is_array($variant)
            || ! in_array($variant['mime_type'] ?? null, ['video/mp4', 'application/x-mpegURL'], true)
            || ! in_array($variant['protocol'] ?? null, ['https', 'hls'], true)
            || ! is_bool($variant['is_preferred'] ?? null)
        ) {
            throw $this->invalidContract($requestId);
        }

        return [
            'url' => $this->safeAssetUrl($variant['url'] ?? null, $requestId),
            'mime_type' => $variant['mime_type'],
            'protocol' => $variant['protocol'],
            'bitrate' => $this->nullablePositiveInt($variant['bitrate'] ?? null),
            'width' => $this->nullablePositiveInt($variant['width'] ?? null),
            'height' => $this->nullablePositiveInt($variant['height'] ?? null),
            'quality_label' => $this->nullableString($variant['quality_label'] ?? null, 40),
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

    private function safeAssetUrl(mixed $url, string $requestId, bool $nullable = false): ?string
    {
        if ($nullable && $url === null) {
            return null;
        }

        if (! is_string($url)) {
            throw $this->invalidContract($requestId);
        }

        $parts = parse_url($url);
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($parts['host'] ?? null, ['pbs.twimg.com', 'video.twimg.com'], true)
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

    private function validationMessage(string $code): string
    {
        return match ($code) {
            'unsupported_scheme' => 'Only HTTP and HTTPS URLs are supported.',
            'embedded_credentials' => 'URLs containing embedded credentials are not allowed.',
            'disallowed_port' => 'URLs with custom ports are not supported.',
            'unsupported_host' => 'This media host is not supported yet.',
            'url_too_long' => 'The media URL is too long.',
            'invalid_x_post_url' => 'Enter a valid X post URL.',
            'no_media' => 'This public X post does not contain directly attached media.',
            'post_unavailable' => 'This X post is unavailable or not public.',
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
