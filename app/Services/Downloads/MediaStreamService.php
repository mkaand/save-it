<?php

namespace App\Services\Downloads;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaStreamService
{
    private const MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'audio/mp4',
        'audio/m4a',
        'audio/mpeg',
        'audio/webm',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(private readonly UpstreamUrlPolicy $policy) {}

    /** @param array<string, mixed> $asset */
    public function stream(array $asset, ?string $range): StreamedResponse
    {
        $provider = $this->requiredString($asset, 'provider');
        $url = $this->requiredString($asset, 'upstream_url');
        $filename = $this->filename($this->requiredString($asset, 'filename'));
        $rangeHeader = $this->range($range);
        $expected = is_int($asset['expected_size'] ?? null) ? $asset['expected_size'] : null;
        if ($expected === null && $rangeHeader === null) {
            $expected = $this->probeSize($url, $provider);
        }
        $response = $this->request($url, $provider, $rangeHeader);

        if ($response->status() === 416) {
            $contentRange = $response->header('Content-Range');
            throw new DownloadException(
                'range_not_satisfiable',
                416,
                'The requested byte range is not available.',
                is_string($contentRange)
                    && preg_match('/^bytes \*\/[0-9]+$/', $contentRange) === 1
                        ? ['Content-Range' => $contentRange]
                        : [],
            );
        }
        if (! in_array($response->status(), [200, 206], true)) {
            throw new DownloadException(
                'upstream_unavailable',
                502,
                'The media source could not be downloaded.',
            );
        }

        $mime = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
        if (! in_array($mime, self::MIME_TYPES, true)) {
            throw new DownloadException(
                'unsupported_media_type',
                422,
                'The media source returned an unsupported file type.',
            );
        }

        $length = $this->positiveHeader($response, 'Content-Length');
        $contentRange = $this->contentRange($response);
        $downstreamRange = null;
        if (
            $rangeHeader !== null
            && (
                $response->status() !== 206
                || $contentRange === null
                || $length === null
                || $length !== ($contentRange['end'] - $contentRange['start'] + 1)
            )
        ) {
            throw new DownloadException(
                'invalid_upstream_range',
                502,
                'The media source returned an invalid byte range response.',
            );
        }
        if ($rangeHeader !== null && $contentRange !== null) {
            $downstreamRange = $this->downstreamRange($rangeHeader, $contentRange);
            if ($downstreamRange === null) {
                throw new DownloadException(
                    'invalid_upstream_range',
                    502,
                    'The media source returned an invalid byte range response.',
                );
            }
        }
        if (
            $response->status() === 206
            && (
                $rangeHeader === null
                || $contentRange === null
                || $length === null
                || $length !== ($contentRange['end'] - $contentRange['start'] + 1)
            )
        ) {
            throw new DownloadException(
                'invalid_upstream_range',
                502,
                'The media source returned an invalid byte range response.',
            );
        }
        $total = $contentRange['total'] ?? null;
        $limit = max(1, (int) config('services.downloads.max_file_bytes'));
        if (
            ($length !== null && $length > $limit)
            || ($total !== null && $total > $limit)
            || ($expected !== null && $expected > $limit)
        ) {
            throw new DownloadException(
                'media_too_large',
                413,
                'The media file exceeds the download size limit.',
            );
        }
        if ($length === null && $total === null && $expected === null) {
            throw new DownloadException(
                'media_size_unknown',
                422,
                'The media source did not provide a safe file size.',
            );
        }

        $disposition = ($asset['disposition'] ?? null) === 'inline' ? 'inline' : 'attachment';
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => $this->contentDisposition($filename, $disposition),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ];
        $downstreamLength = $downstreamRange === null
            ? $length
            : $downstreamRange['end'] - $downstreamRange['start'] + 1;
        if ($downstreamLength !== null) {
            $headers['Content-Length'] = (string) $downstreamLength;
        }
        if ($response->status() === 206 && $downstreamRange !== null) {
            $headers['Content-Range'] = sprintf(
                'bytes %d-%d/%d',
                $downstreamRange['start'],
                $downstreamRange['end'],
                $downstreamRange['total'],
            );
            $headers['Accept-Ranges'] = 'bytes';
        }

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body, $downstreamLength, $limit): void {
            $sent = 0;
            try {
                while (($downstreamLength === null || $sent < $downstreamLength) && ! $body->eof()) {
                    if (connection_aborted()) {
                        break;
                    }
                    try {
                        $chunk = $body->read(min(
                            64 * 1024,
                            $downstreamLength === null ? 64 * 1024 : $downstreamLength - $sent,
                        ));
                    } catch (\RuntimeException) {
                        break;
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $sent += strlen($chunk);
                    if ($sent > $limit) {
                        break;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                $body->close();
            }
        }, $response->status(), $headers);
    }

    private function request(string $url, string $provider, ?string $range): Response
    {
        $redirects = max(0, (int) config('services.downloads.max_redirects'));
        for ($attempt = 0; $attempt <= $redirects; $attempt++) {
            $url = $this->policy->validate($url, $provider);
            $request = Http::accept('*/*')
                ->connectTimeout((float) config('services.downloads.connect_timeout_seconds'))
                ->timeout((float) config('services.downloads.timeout_seconds'))
                ->withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'proxy' => '',
                ]);
            if ($range !== null) {
                $request = $request->withHeader('Range', $range);
            }
            $response = $request->get($url);

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
            'The media source returned an unsafe redirect.',
        );
    }

    private function probeSize(string $url, string $provider): ?int
    {
        $response = $this->request($url, $provider, 'bytes=0-0');
        try {
            if (! in_array($response->status(), [200, 206], true)) {
                return null;
            }
            $mime = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
            if (! in_array($mime, self::MIME_TYPES, true)) {
                throw new DownloadException(
                    'unsupported_media_type',
                    422,
                    'The media source returned an unsupported file type.',
                );
            }
            if ($response->status() === 206) {
                $range = $this->contentRange($response);
                $length = $this->positiveHeader($response, 'Content-Length');
                if ($range === null || $length !== 1 || $range['start'] !== 0 || $range['end'] !== 0) {
                    throw new DownloadException(
                        'invalid_upstream_range',
                        502,
                        'The media source returned an invalid byte range response.',
                    );
                }

                return $range['total'];
            }

            return $this->positiveHeader($response, 'Content-Length');
        } finally {
            $response->toPsrResponse()->getBody()->close();
        }
    }

    private function range(?string $range): ?string
    {
        if ($range === null || $range === '') {
            return null;
        }
        if (preg_match('/^bytes=(?:[0-9]+-[0-9]*|-[0-9]+)$/', $range) !== 1) {
            throw new DownloadException(
                'range_not_satisfiable',
                416,
                'Only one byte range may be requested at a time.',
            );
        }

        return $range;
    }

    /**
     * @param  array{start: int, end: int, total: int}  $contentRange
     * @return array{start: int, end: int, total: int}|null
     */
    private function downstreamRange(string $requested, array $contentRange): ?array
    {
        $requestedRange = $this->resolvedRequestedRange($requested, $contentRange['total']);
        if ($requestedRange === null) {
            return null;
        }

        if (
            $contentRange['start'] >= $requestedRange['start']
            && $contentRange['end'] <= $requestedRange['end']
        ) {
            return $contentRange;
        }

        if (
            $contentRange['start'] === $requestedRange['start']
            && $contentRange['end'] >= $requestedRange['end']
        ) {
            return $requestedRange;
        }

        return null;
    }

    /** @return array{start: int, end: int, total: int}|null */
    private function resolvedRequestedRange(string $requested, int $total): ?array
    {
        if (preg_match('/^bytes=([0-9]+)-([0-9]+)$/', $requested, $matches) === 1) {
            $start = (int) $matches[1];
            $end = (int) $matches[2];
            if ($start > $end || $start >= $total) {
                return null;
            }

            return [
                'start' => $start,
                'end' => min($end, $total - 1),
                'total' => $total,
            ];
        }

        if (preg_match('/^bytes=([0-9]+)-$/', $requested, $matches) === 1) {
            $start = (int) $matches[1];
            if ($start >= $total) {
                return null;
            }

            return ['start' => $start, 'end' => $total - 1, 'total' => $total];
        }

        if (preg_match('/^bytes=-([0-9]+)$/', $requested, $matches) !== 1) {
            return null;
        }

        $suffixLength = (int) $matches[1];
        if ($suffixLength < 1) {
            return null;
        }

        return [
            'start' => max(0, $total - $suffixLength),
            'end' => $total - 1,
            'total' => $total,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new DownloadException(
                'invalid_download_token',
                410,
                'This download link is no longer valid. Analyze the URL again.',
            );
        }

        return $value;
    }

    private function positiveHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @return array{start: int, end: int, total: int}|null */
    private function contentRange(Response $response): ?array
    {
        $value = $response->header('Content-Range');
        if (
            ! is_string($value)
            || preg_match('/^bytes ([0-9]+)-([0-9]+)\/([0-9]+)$/', $value, $matches) !== 1
        ) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];
        $total = (int) $matches[3];
        if ($start > $end || $end >= $total || $total < 1) {
            return null;
        }

        return compact('start', 'end', 'total');
    }

    private function filename(string $value): string
    {
        $value = preg_replace('/[^\pL\pN._ -]+/u', '-', $value) ?? 'media';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? 'media', " .-\t\n\r\0\x0B");

        return mb_substr($value !== '' ? $value : 'media', 0, 180);
    }

    private function contentDisposition(string $filename, string $disposition): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'media';

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            addcslashes($fallback, '"\\'),
            rawurlencode($filename),
        );
    }
}
