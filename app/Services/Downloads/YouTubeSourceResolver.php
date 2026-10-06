<?php

namespace App\Services\Downloads;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class YouTubeSourceResolver
{
    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function resolve(array $asset): array
    {
        $url = $asset['normalized_url'] ?? null;
        $formatId = $asset['format_id'] ?? null;
        $sourceClient = $asset['source_client'] ?? null;
        if (
            ! is_string($url)
            || ! is_string($formatId)
            || preg_match('/^[A-Za-z0-9._+-]{1,100}$/', $formatId) !== 1
            || ($sourceClient !== null && ! in_array($sourceClient, ['default', 'android'], true))
        ) {
            throw new DownloadException(
                'invalid_download_token',
                410,
                'This download link is no longer valid. Analyze the URL again.',
            );
        }

        $requestId = (string) Str::uuid();
        try {
            $response = Http::baseUrl(rtrim((string) config('services.extractor.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout((float) config('services.extractor.connect_timeout_seconds'))
                ->timeout((float) config('services.extractor.timeout_seconds'))
                ->withOptions(['allow_redirects' => false])
                ->post('/v1/youtube/resolve', [
                    'url' => $url,
                    'format_id' => $formatId,
                    ...($sourceClient === null ? [] : ['source_client' => $sourceClient]),
                    'request_id' => $requestId,
                ]);
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        $data = $response->json('data');
        if ($response->status() === 410) {
            throw new DownloadException('format_unavailable', 410, 'The selected source expired. Analyze the URL again.');
        }
        if (
            ! $response->successful()
            || ! is_array($data)
            || ($data['request_id'] ?? null) !== $requestId
            || ($data['provider'] ?? null) !== 'youtube'
            || ($data['normalized_url'] ?? null) !== $url
            || ($data['format_id'] ?? null) !== $formatId
            || ! is_string($data['url'] ?? null)
        ) {
            throw $this->unavailable();
        }
        foreach (['video_codec', 'audio_codec'] as $codec) {
            if (isset($asset[$codec]) && ($data[$codec] ?? null) !== $asset[$codec]) {
                throw new DownloadException('format_unavailable', 410, 'The selected source changed. Analyze the URL again.');
            }
        }

        return [
            ...$asset,
            'provider' => 'youtube',
            'upstream_url' => $data['url'],
            'mime_type' => is_string($data['mime_type'] ?? null)
                ? $data['mime_type']
                : ($asset['mime_type'] ?? null),
            'expected_size' => is_int($data['estimated_filesize'] ?? null)
                ? $data['estimated_filesize']
                : ($asset['expected_size'] ?? null),
        ];
    }

    private function unavailable(): DownloadException
    {
        return new DownloadException(
            'upstream_unavailable',
            503,
            'The selected YouTube format is temporarily unavailable.',
        );
    }
}
