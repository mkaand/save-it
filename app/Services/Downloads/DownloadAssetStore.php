<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DownloadAssetStore
{
    /** @param array<string, mixed> $payload */
    public function issue(array $payload): string
    {
        $identifier = Str::lower(Str::random(48));
        $signature = hash_hmac('sha256', $identifier, $this->signingKey());
        $ttl = max(60, (int) config('services.downloads.token_ttl_seconds'));

        Cache::put($this->cacheKey($identifier), [
            ...$payload,
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ], $ttl);

        return "{$identifier}.{$signature}";
    }

    /** @return array<string, mixed> */
    public function resolve(string $token): array
    {
        return $this->payload($token, false);
    }

    /** @return array<string, mixed> */
    public function consume(string $token): array
    {
        return $this->payload($token, true);
    }

    /** @return array<string, mixed> */
    private function payload(string $token, bool $consume): array
    {
        if (preg_match('/^(?<id>[a-z0-9]{48})\.(?<signature>[a-f0-9]{64})$/', $token, $matches) !== 1) {
            throw $this->expired();
        }

        $expected = hash_hmac('sha256', $matches['id'], $this->signingKey());
        if (! hash_equals($expected, $matches['signature'])) {
            throw $this->expired();
        }

        $key = $this->cacheKey($matches['id']);
        $payload = $consume ? Cache::pull($key) : Cache::get($key);
        if (! is_array($payload)) {
            throw $this->expired();
        }

        return $payload;
    }

    private function cacheKey(string $identifier): string
    {
        return 'save-it:download-token:v1:'.hash('sha256', $identifier);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DownloadException(
                'download_unavailable',
                503,
                'Download delivery is temporarily unavailable.',
            );
        }

        return $key;
    }

    private function expired(): DownloadException
    {
        return new DownloadException(
            'download_token_expired',
            410,
            'This download link has expired. Analyze the URL again.',
        );
    }
}
