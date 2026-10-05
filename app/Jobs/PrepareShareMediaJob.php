<?php

namespace App\Jobs;

use App\Services\Downloads\DownloadException;
use App\Services\Downloads\ShareMediaPreparationJobStore;
use App\Services\Downloads\ShareMediaPreparationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class PrepareShareMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $jobId)
    {
        $this->timeout = max(60, (int) config('services.downloads.job_timeout_seconds'));
    }

    public function handle(ShareMediaPreparationJobStore $jobs, ShareMediaPreparationService $preparations): void
    {
        try {
            $state = $jobs->get($this->jobId);
            $asset = $state['asset'] ?? null;
            if (! is_array($asset)) {
                throw new DownloadException('share_unavailable', 410, 'This file cannot be prepared for sharing.');
            }
            $jobs->update($this->jobId, ['status' => 'processing', 'stage' => 'Downloading', 'progress' => null]);
            $prepared = $preparations->prepareAsset($asset, function (string $stage, ?int $progress) use ($jobs): void {
                $jobs->update($this->jobId, [
                    'status' => 'processing',
                    'stage' => $stage,
                    'progress' => $progress === null ? null : min(99, max(0, $progress)),
                ]);
            });
            $jobs->update($this->jobId, [
                'status' => 'ready',
                'stage' => 'Ready',
                'progress' => 100,
                'prepared_url' => route('api.share-preparations.show', ['preparation' => $prepared['id']], false),
                'filename' => $prepared['filename'],
                'mime_type' => 'video/mp4',
                'asset' => null,
            ]);
        } catch (Throwable $exception) {
            try {
                $this->markFailed($jobs, $exception);
            } catch (Throwable) {
                // The ephemeral state may have expired while the job ran.
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->markFailed(app(ShareMediaPreparationJobStore::class), $exception);
        } catch (Throwable) {
            // The job state may already have expired; do not leak job input.
        }
    }

    private function markFailed(ShareMediaPreparationJobStore $jobs, Throwable $exception): void
    {
        $jobs->update($this->jobId, [
            'status' => 'failed',
            'stage' => 'Failed',
            'progress' => null,
            'asset' => null,
            'error' => [
                'code' => $exception instanceof DownloadException ? $exception->publicCode : 'share_unavailable',
                'message' => $exception instanceof DownloadException ? $exception->getMessage() : 'The file could not be prepared for sharing.',
            ],
        ]);
    }
}
