<?php

namespace App\Services\Downloads;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class UpstreamFileDownloader
{
    private const CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly UpstreamUrlPolicy $policy) {}

    /** @param array<string, mixed> $asset */
    public function download(array $asset, string $destination, int $remainingLimit): int
    {
        $provider = $asset['provider'] ?? null;
        $url = $asset['upstream_url'] ?? null;
        if (! is_string($provider) || ! is_string($url)) {
            throw new DownloadException('invalid_download_token', 410, 'The download plan is invalid.');
        }
        $sizeHint = is_int($asset['expected_size'] ?? null)
            && $asset['expected_size'] > 0
            && $asset['expected_size'] <= $remainingLimit
                ? $asset['expected_size']
                : null;
        $handle = fopen($destination, 'xb');
        if ($handle === false) {
            throw new DownloadException('job_failed', 500, 'The download could not be prepared.');
        }
        $written = 0;
        try {
            $maximumChunks = max(1, (int) ceil($remainingLimit / self::CHUNK_BYTES));
            for ($index = 0; $index < $maximumChunks; $index++) {
                $end = min($remainingLimit - 1, $written + self::CHUNK_BYTES - 1);
                if ($sizeHint !== null) {
                    $end = min($end, $sizeHint - 1);
                }
                $response = $this->request($url, $provider, "bytes={$written}-{$end}");
                if (! in_array($response->status(), [200, 206], true)) {
                    $this->logYouTubeFailure($provider, $response, 'youtube_upstream_http_status');
                    throw new DownloadException(
                        'upstream_unavailable',
                        502,
                        'A media source could not be downloaded.',
                    );
                }
                if ($response->status() === 200 && $written !== 0) {
                    $this->logYouTubeFailure($provider, $response, 'youtube_range_ignored_on_later_chunk');
                    throw new DownloadException(
                        'upstream_unavailable',
                        502,
                        'A media source could not be downloaded.',
                    );
                }

                $this->assertContentType($response);
                $range = $response->status() === 206
                    ? $this->contentRange($response, $written, $remainingLimit)
                    : null;
                $expectedLength = $range['length'] ?? $this->contentLength($response);
                if ($expectedLength !== null && $written + $expectedLength > $remainingLimit) {
                    throw new DownloadException(
                        'media_too_large',
                        413,
                        'The prepared download exceeds the size limit.',
                    );
                }

                $written += $this->writeBody(
                    $response,
                    $handle,
                    $expectedLength,
                    $remainingLimit - $written,
                );
                if (
                    $response->status() === 200
                    || ($range !== null && $written >= $range['total'])
                ) {
                    return $written;
                }
            }
        } finally {
            fclose($handle);
        }

        throw new DownloadException(
            'media_too_large',
            413,
            'The prepared download exceeds the size limit.',
        );
    }

    private function request(string $url, string $provider, string $range): Response
    {
        $redirects = max(0, (int) config('services.downloads.max_redirects'));
        for ($attempt = 0; $attempt <= $redirects; $attempt++) {
            $url = $this->policy->validate($url, $provider);
            $response = Http::accept('*/*')
                ->connectTimeout((float) config('services.downloads.connect_timeout_seconds'))
                ->timeout((float) config('services.downloads.timeout_seconds'))
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'proxy' => ''])
                ->withHeader('Range', $range)
                ->get($url);
            if (! $response->redirect()) {
                return $response;
            }
            if ($attempt === $redirects) {
                break;
            }
            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                break;
            }
            $url = $this->policy->redirect($url, $location, $provider);
        }

        throw new DownloadException(
            'upstream_redirect_rejected',
            502,
            'A media source returned an unsafe redirect.',
        );
    }

    private function assertContentType(Response $response): void
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
        if (! preg_match('#^(?:video|audio|image)/[A-Za-z0-9.+-]+$#', $contentType)) {
            throw new DownloadException(
                'unsupported_media_type',
                422,
                'A media source returned an unsupported file type.',
            );
        }
    }

    /** @return array{length: int, total: int} */
    private function contentRange(Response $response, int $expectedStart, int $limit): array
    {
        $header = $response->header('Content-Range', '');
        if (
            ! is_string($header)
            || preg_match('/^bytes ([0-9]+)-([0-9]+)\\/([0-9]+)$/', $header, $matches) !== 1
        ) {
            throw new DownloadException('upstream_unavailable', 502, 'A media source could not be downloaded.');
        }
        $start = (int) $matches[1];
        $end = (int) $matches[2];
        $total = (int) $matches[3];
        if (
            $start !== $expectedStart
            || $end < $start
            || $end >= $total
            || ($end - $start + 1) > self::CHUNK_BYTES
            || $total > $limit
        ) {
            throw new DownloadException(
                $total > $limit ? 'media_too_large' : 'upstream_unavailable',
                $total > $limit ? 413 : 502,
                $total > $limit
                    ? 'The prepared download exceeds the size limit.'
                    : 'A media source could not be downloaded.',
            );
        }

        return ['length' => $end - $start + 1, 'total' => $total];
    }

    private function contentLength(Response $response): ?int
    {
        $length = filter_var(
            $response->header('Content-Length'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        return is_int($length) ? $length : null;
    }

    private function logYouTubeFailure(string $provider, Response $response, string $reason): void
    {
        if ($provider !== 'youtube') {
            return;
        }

        Log::warning('youtube_media_delivery_failed', [
            'provider' => $provider,
            'pipeline_stage' => 'job_source_download',
            'delivery_path' => 'job',
            'upstream_status' => $response->status(),
            'requested_range_class' => 'single',
            'content_range_classification' => $response->header('Content-Range') === null ? 'none_or_invalid' : 'present',
            'content_length_present' => $response->header('Content-Length') !== null,
            'failure_reason' => $reason,
        ]);
    }

    /** @param resource $handle */
    private function writeBody(
        Response $response,
        mixed $handle,
        ?int $expectedLength,
        int $remainingLimit,
    ): int {
        $body = $response->toPsrResponse()->getBody();
        $written = 0;
        try {
            while (($expectedLength === null || $written < $expectedLength) && ! $body->eof()) {
                try {
                    $chunk = $body->read(min(
                        64 * 1024,
                        $expectedLength === null ? 64 * 1024 : $expectedLength - $written,
                    ));
                } catch (\RuntimeException) {
                    throw new DownloadException(
                        'upstream_unavailable',
                        502,
                        'A media source could not be downloaded.',
                    );
                }
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $remainingLimit) {
                    throw new DownloadException(
                        'media_too_large',
                        413,
                        'The prepared download exceeds the size limit.',
                    );
                }
                if (fwrite($handle, $chunk) === false) {
                    throw new DownloadException('job_failed', 500, 'The download could not be prepared.');
                }
            }
        } finally {
            $body->close();
        }
        if ($expectedLength !== null && $written !== $expectedLength) {
            throw new DownloadException(
                'upstream_unavailable',
                502,
                'A media source could not be downloaded.',
            );
        }

        return $written;
    }
}
