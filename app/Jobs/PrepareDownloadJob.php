<?php

namespace App\Jobs;

use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\DownloadJobStore;
use App\Services\Downloads\DownloadPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class PrepareDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $jobId,
        public readonly array $plan,
    ) {
        $this->timeout = max(60, (int) config('services.downloads.job_timeout_seconds'));
    }

    public function handle(
        DownloadPipeline $pipeline,
        DownloadJobStore $jobs,
        DownloadAssetStore $tokens,
    ): void {
        $jobs->update($this->jobId, [
            'status' => 'processing',
            'stage' => 'Preparing',
            'progress' => 5,
        ]);

        try {
            $result = $pipeline->prepare(
                $this->plan,
                fn (string $stage, int $progress) => $jobs->update($this->jobId, [
                    'status' => 'processing',
                    'stage' => $stage,
                    'progress' => min(95, max(5, $progress)),
                ]),
            );
            $token = $tokens->issue([
                'version' => 1,
                'mode' => 'local_file',
                ...$result,
            ]);
            $jobs->update($this->jobId, [
                'status' => 'ready',
                'stage' => 'Ready',
                'progress' => 100,
                'download_url' => route('api.downloads.show', ['token' => $token], false),
                'size' => $result['size'],
            ]);
        } catch (Throwable $exception) {
            $jobs->update($this->jobId, [
                'status' => 'failed',
                'stage' => 'Failed',
                'progress' => 100,
                'error' => [
                    'code' => $exception instanceof DownloadException
                        ? $exception->publicCode
                        : 'job_failed',
                    'message' => $exception instanceof DownloadException
                        ? $exception->getMessage()
                        : 'The media could not be prepared.',
                ],
            ]);
        }
    }
}
