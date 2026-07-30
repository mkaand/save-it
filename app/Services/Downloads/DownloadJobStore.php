<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DownloadJobStore
{
    /** @param array<string, mixed> $state */
    public function create(array $state): string
    {
        $id = (string) Str::uuid();
        $this->put($id, [
            ...$state,
            'status' => 'queued',
            'progress' => 0,
            'stage' => 'Preparing',
            'created_at' => now()->toIso8601String(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void
    {
        $current = $this->get($id);
        $this->put($id, [...$current, ...$changes]);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        if (Str::isUuid($id) === false) {
            throw $this->missing();
        }
        $state = Cache::get($this->key($id));
        if (! is_array($state)) {
            throw $this->missing();
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function put(string $id, array $state): void
    {
        Cache::put(
            $this->key($id),
            $state,
            max(300, (int) config('services.downloads.job_ttl_seconds')),
        );
    }

    private function key(string $id): string
    {
        return 'save-it:download-job:v1:'.$id;
    }

    private function missing(): DownloadException
    {
        return new DownloadException(
            'download_job_expired',
            410,
            'This preparation job has expired. Analyze the URL again.',
        );
    }
}
