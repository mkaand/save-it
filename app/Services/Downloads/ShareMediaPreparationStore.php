<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class ShareMediaPreparationStore
{
    public function issue(string $path, string $filename): string
    {
        $identifier = pathinfo($path, PATHINFO_FILENAME);
        if (! is_string($identifier) || preg_match('/^[a-z0-9]{48}$/', $identifier) !== 1) {
            throw new DownloadException('share_unavailable', 503, 'The file could not be prepared for sharing.');
        }
        $ttl = $this->ttl();
        $this->cleanupExpired();

        Cache::put($this->cacheKey($identifier), [
            'file' => basename($path),
            'filename' => $filename,
        ], $ttl);

        return $identifier;
    }

    /** @return array{path: string, filename: string}|null */
    public function resolve(string $identifier): ?array
    {
        if (preg_match('/^[a-z0-9]{48}$/', $identifier) !== 1) {
            return null;
        }

        $record = Cache::get($this->cacheKey($identifier));
        if (! is_array($record) || ! is_string($record['file'] ?? null) || ! is_string($record['filename'] ?? null)) {
            $this->delete($identifier);

            return null;
        }

        $root = realpath($this->directory());
        $path = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$record['file']);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            Cache::forget($this->cacheKey($identifier));

            return null;
        }

        return ['path' => $path, 'filename' => $record['filename']];
    }

    public function directory(): string
    {
        return storage_path('app/private/share-preparations');
    }

    public function cleanupExpired(): void
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return;
        }
        $cutoff = time() - $this->ttl();
        $files = glob($directory.DIRECTORY_SEPARATOR.'*.mp4') ?: [];
        usort($files, static fn (string $left, string $right): int => (filemtime($left) ?: PHP_INT_MAX) <=> (filemtime($right) ?: PHP_INT_MAX));

        foreach (array_slice($files, 0, 25) as $file) {
            if (is_file($file) && (filemtime($file) ?: PHP_INT_MAX) < $cutoff) {
                File::delete($file);
            }
        }
    }

    private function delete(string $identifier): void
    {
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.$identifier.'.mp4') ?: [] as $file) {
            File::delete($file);
        }
    }

    private function cacheKey(string $identifier): string
    {
        return 'save-it:share-preparation:v1:'.hash('sha256', $identifier);
    }

    private function ttl(): int
    {
        return max(60, (int) config('services.downloads.share_preparation_ttl_seconds'));
    }
}
