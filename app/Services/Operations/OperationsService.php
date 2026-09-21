<?php

namespace App\Services\Operations;

use App\Services\Downloads\ShareMediaPreparationStore;
use App\Services\Previews\RecentPreviewStore;
use App\Services\Runtime\RuntimeArtifactCleanup;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class OperationsService
{
    /** @return array<string, array{count:int,size:int}> */
    public function storage(): array
    {
        return [
            'share_preparations' => $this->directory(storage_path('app/private/share-preparations')),
            'downloads' => $this->directory(storage_path('app/private/downloads')),
            'recent_previews' => $this->directory(storage_path('app/private/recent-previews')),
        ];
    }

    /** @return array{queue_depth:int,failed_jobs:int} */
    public function queue(): array
    {
        try {
            $depth = (int) app('queue')->size();
        } catch (\Throwable) {
            $depth = 0;
        }

        try {
            $failed = Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0;
        } catch (\Throwable) {
            $failed = 0;
        }

        return ['queue_depth' => $depth, 'failed_jobs' => $failed];
    }

    /** @return array{storage:array<string, array{count:int,size:int}>,queue:array{queue_depth:int,failed_jobs:int}} */
    public function snapshot(): array
    {
        return ['storage' => $this->storage(), 'queue' => $this->queue()];
    }

    public function cleanup(): void
    {
        app(RuntimeArtifactCleanup::class)->downloads();
        app(ShareMediaPreparationStore::class)->cleanupExpired();
        app(RecentPreviewStore::class)->cleanupExpired();

        app(ApplicationSettings::class)->put('operations.cleanup.last_at', now()->toIso8601String());
        app(ApplicationSettings::class)->put('operations.cleanup.last_status', 'completed');
    }

    /** @return array{count:int,size:int} */
    private function directory(string $directory): array
    {
        if (! is_dir($directory)) {
            return ['count' => 0, 'size' => 0];
        }

        $root = realpath($directory);
        if ($root === false) {
            return ['count' => 0, 'size' => 0];
        }

        $count = 0;
        $size = 0;
        foreach (File::allFiles($root, true) as $file) {
            if (is_link($file->getPathname())) {
                continue;
            }
            $count++;
            $size += $file->getSize();
        }

        return ['count' => $count, 'size' => $size];
    }
}
