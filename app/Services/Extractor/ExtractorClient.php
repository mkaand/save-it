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
