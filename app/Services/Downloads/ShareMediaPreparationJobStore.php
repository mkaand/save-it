<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;

final class ShareMediaPreparationJobStore
{
    /** @param array<string, mixed> $asset */
    public function create(array $asset): string
    {
        $id = strtolower(bin2hex(random_bytes(24)));
        $this->put($id, [
            'asset' => $asset,
            'status' => 'queued',
            'stage' => 'Queued',
            'progress' => null,
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        if (! $this->valid($id)) {
            throw $this->missing();
        }

        $state = Cache::get($this->key($id));
        if (! is_array($state)) {
            throw $this->missing();
        }

        return $state;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void
    {
        $this->put($id, [...$this->get($id), ...$changes]);
    }

    /** @param array<string, mixed> $state */
    private function put(string $id, array $state): void
    {
        Cache::put($this->key($id), $state, max(300, (int) config('services.downloads.job_ttl_seconds')));
    }

    private function key(string $id): string
    {
        return 'save-it:share-preparation-job:v1:'.hash('sha256', $id);
    }

    private function valid(string $id): bool
    {
        return preg_match('/^[a-z0-9]{48}$/', $id) === 1;
    }

    private function missing(): DownloadException
    {
        return new DownloadException(
            'share_preparation_expired',
            410,
            'This preparation job has expired. Analyze the URL again.',
        );
    }
}
