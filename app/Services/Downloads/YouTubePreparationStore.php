<?php

namespace App\Services\Downloads;

use App\Jobs\PrepareDownloadJob;
use Illuminate\Support\Facades\Cache;

final class YouTubePreparationStore
{
    public function assertActive(array $plan): void
    {
        $slot = $plan['_slot'] ?? [];
        if (! in_array($slot['name'] ?? null, ['save-it:youtube-mux-slot:0', 'save-it:youtube-mux-slot:1'], true)
            || ! is_string($slot['owner'] ?? null)
            || ! Cache::restoreLock($slot['name'], $slot['owner'])->isOwnedByCurrentProcess()) {
            throw new DownloadException('preparation_expired', 410, 'Preparation expired in the queue. Analyze the URL again.');
        }
    }

    public function start(string $token, DownloadAssetStore $tokens, DownloadJobStore $jobs): string
    {
        $key = 'save-it:youtube-preparation:'.hash('sha256', $token);

        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $token, $tokens, $jobs): string {
            $existing = Cache::get($key);
            if (is_string($existing)) {
                $jobs->get($existing);

                return $existing;
            }
            $plan = $tokens->resolve($token);
            if (($plan['mode'] ?? null) !== 'youtube_merge') {
                throw new DownloadException('invalid_download_token', 410, 'The download plan is invalid.');
            }
            // Two outstanding anonymous mux jobs globally, not merely per IP.
            // The lease covers queue wait + the bounded worker deadline.
            for ($slot = 0; $slot < 2; $slot++) {
                $name = 'save-it:youtube-mux-slot:'.$slot;
                $lock = Cache::lock($name, 3600);
                if (! $lock->get()) {
                    continue;
                }
                try {
                    $plan['_slot'] = ['name' => $name, 'owner' => $lock->owner()];
                    $plan['_directory'] = bin2hex(random_bytes(16));
                    $id = $jobs->create(['mode' => 'youtube_merge']);
                    PrepareDownloadJob::dispatch($id, $plan);
                    Cache::put($key, $id, 3600);

                    return $id;
                } catch (\Throwable $exception) {
                    $lock->release();
                    throw $exception;
                }
            }
            throw new DownloadException('preparation_busy', 429, 'Video preparation is busy. Please try again shortly.');
        });
    }

    public function release(array $plan): void
    {
        $slot = $plan['_slot'] ?? null;
        if (is_array($slot) && in_array($slot['name'] ?? null, ['save-it:youtube-mux-slot:0', 'save-it:youtube-mux-slot:1'], true)
            && is_string($slot['owner'] ?? null)) {
            Cache::restoreLock($slot['name'], $slot['owner'])->release();
        }
    }
}
