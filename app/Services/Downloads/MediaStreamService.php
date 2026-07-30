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
        $total = $this->contentRangeTotal($response);
        $expected = is_int($asset['expected_size'] ?? null) ? $asset['expected_size'] : null;
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
        foreach (['Content-Length', 'Content-Range', 'Accept-Ranges'] as $header) {
            $value = $response->header($header);
            if (is_string($value) && $value !== '') {
                $headers[$header] = $value;
            }
        }

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body, $length, $limit): void {
            $sent = 0;
            try {
                while (($length === null || $sent < $length) && ! $body->eof()) {
                    if (connection_aborted()) {
                        break;
                    }
                    try {
                        $chunk = $body->read(min(64 * 1024, $length === null ? 64 * 1024 : $length - $sent));
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

    private function contentRangeTotal(Response $response): ?int
    {
        $value = $response->header('Content-Range');
        if (! is_string($value) || preg_match('/^bytes [0-9]+-[0-9]+\/([0-9]+)$/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
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
