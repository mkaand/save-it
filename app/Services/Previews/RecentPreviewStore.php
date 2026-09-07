<?php

namespace App\Services\Previews;

use App\Services\Downloads\DownloadException;
use App\Services\Downloads\UpstreamUrlPolicy;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class RecentPreviewStore
{
    private const TTL_SECONDS = 2592000;

    private const MAX_BYTES = 524288;

    private const MAX_DIMENSION = 1600;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly UpstreamUrlPolicy $policy) {}

    /** @param array<string, mixed> $asset */
    public function issue(array $asset): string
    {
        $identifier = Str::lower(Str::random(48));
        $provider = $this->requiredString($asset, 'provider');
        $url = $this->requiredString($asset, 'upstream_url');
        $response = $this->request($url, $provider);
        $file = null;

        try {
            if ($response->status() !== 200) {
                throw new DownloadException('preview_unavailable', 502, 'The preview source is unavailable.');
            }

            $mime = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
            if (! array_key_exists($mime, self::MIME_EXTENSIONS)) {
                throw new DownloadException('preview_unsupported_type', 422, 'The preview source is not an image.');
            }
            $declaredSize = $this->positiveHeader($response, 'Content-Length');
            if ($declaredSize !== null && $declaredSize > self::MAX_BYTES) {
                throw new DownloadException('preview_too_large', 413, 'The preview source is too large.');
            }

            $file = $this->writeImage($identifier, $response, $mime);
            $this->cleanupExpired();
            Cache::put($this->cacheKey($identifier), [
                'file' => basename($file),
                'mime_type' => $mime,
            ], self::TTL_SECONDS);

            return $identifier;
        } catch (\Throwable $exception) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }

            throw $exception;
        } finally {
            $response->toPsrResponse()->getBody()->close();
        }
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $identifier): ?array
    {
        if (preg_match('/^[a-z0-9]{48}$/', $identifier) !== 1) {
            return null;
        }

        $record = Cache::get($this->cacheKey($identifier));
        if (! is_array($record) || ! is_string($record['file'] ?? null) || ! is_string($record['mime_type'] ?? null)) {
            $this->deleteFiles($identifier);

            return null;
        }

        $path = realpath($this->directory().DIRECTORY_SEPARATOR.$record['file']);
        $root = realpath($this->directory());
        if (
            $path === false
            || $root === false
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || ! array_key_exists($record['mime_type'], self::MIME_EXTENSIONS)
        ) {
            Cache::forget($this->cacheKey($identifier));

            return null;
        }

        return ['path' => $path, 'mime_type' => $record['mime_type']];
    }

    private function request(string $url, string $provider): Response
    {
        $redirects = max(0, (int) config('services.downloads.max_redirects'));
        for ($attempt = 0; $attempt <= $redirects; $attempt++) {
            $url = $this->policy->validate($url, $provider);
            $response = Http::accept('image/*')
                ->connectTimeout((float) config('services.downloads.connect_timeout_seconds'))
                ->timeout((float) config('services.downloads.timeout_seconds'))
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'proxy' => ''])
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

        throw new DownloadException('preview_redirect_rejected', 502, 'The preview source redirected unsafely.');
    }

    private function writeImage(string $identifier, Response $response, string $mime): string
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new DownloadException('preview_unavailable', 503, 'The preview cache is unavailable.');
        }
        $temporary = tempnam($directory, 'preview-');
        if ($temporary === false || ($handle = fopen($temporary, 'wb')) === false) {
            throw new DownloadException('preview_unavailable', 503, 'The preview cache is unavailable.');
        }

        $bytes = 0;
        $body = $response->toPsrResponse()->getBody();
        try {
            while (! $body->eof()) {
                $chunk = $body->read(64 * 1024);
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) {
                    throw new DownloadException('preview_too_large', 413, 'The preview source is too large.');
                }
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        $dimensions = @getimagesize($temporary);
        $detectedMime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
        if (
            ! is_array($dimensions)
            || ! is_int($dimensions[0] ?? null)
            || ! is_int($dimensions[1] ?? null)
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] > self::MAX_DIMENSION
            || $dimensions[1] > self::MAX_DIMENSION
            || $detectedMime !== $mime
        ) {
            @unlink($temporary);
            throw new DownloadException('preview_invalid_image', 422, 'The preview source is not a supported image.');
        }

        $target = $directory.DIRECTORY_SEPARATOR.$identifier.'.'.self::MIME_EXTENSIONS[$mime];
        if (! rename($temporary, $target)) {
            @unlink($temporary);
            throw new DownloadException('preview_unavailable', 503, 'The preview cache is unavailable.');
        }
        chmod($target, 0600);

        return $target;
    }

    private function cleanupExpired(): void
    {
        $cutoff = time() - self::TTL_SECONDS;
        foreach (array_slice(glob($this->directory().DIRECTORY_SEPARATOR.'*') ?: [], 0, 25) as $file) {
            if (is_file($file) && filemtime($file) !== false && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function deleteFiles(string $identifier): void
    {
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.$identifier.'.*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function directory(): string
    {
        return storage_path('app/private/recent-previews');
    }

    /** @param array<string, mixed> $asset */
    private function requiredString(array $asset, string $key): string
    {
        $value = $asset[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new DownloadException('preview_unavailable', 503, 'The preview source is unavailable.');
        }

        return $value;
    }

    private function positiveHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function cacheKey(string $identifier): string
    {
        return 'save-it:recent-preview:v1:'.hash('sha256', $identifier);
    }
}
