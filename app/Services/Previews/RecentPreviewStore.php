<?php

namespace App\Services\Previews;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class RecentPreviewStore
{
    private const TTL_SECONDS = 2592000;

    /** @param array<string, mixed> $asset */
    public function issue(array $asset): string
    {
        $identifier = Str::lower(Str::random(48));

        Cache::put($this->cacheKey($identifier), $asset, self::TTL_SECONDS);

        return $identifier;
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $identifier): ?array
    {
        if (preg_match('/^[a-z0-9]{48}$/', $identifier) !== 1) {
            return null;
        }

        $asset = Cache::get($this->cacheKey($identifier));

        return is_array($asset) ? $asset : null;
    }

    private function cacheKey(string $identifier): string
    {
        return 'save-it:recent-preview:v1:'.hash('sha256', $identifier);
    }
}
