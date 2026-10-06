<?php

namespace App\Jobs;

use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\DownloadJobStore;
use App\Services\Downloads\DownloadPipeline;
use App\Services\Downloads\YouTubePreparationStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
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
            'progress' => ($this->plan['mode'] ?? null) === 'youtube_merge' ? null : 5,
        ]);

        try {
            if (($this->plan['mode'] ?? null) === 'youtube_merge') {
                app(YouTubePreparationStore::class)->assertActive($this->plan);
            }
            $result = $pipeline->prepare(
                $this->plan,
                fn (string $stage, ?int $progress) => $jobs->update($this->jobId, [
                    'status' => 'processing',
                    'stage' => $stage,
                    'progress' => $progress === null ? null : min(100, max(0, $progress)),
                ]),
            );
            $token = $tokens->issue([
                'version' => 1,
                'mode' => 'local_file',
                ...match ($this->plan['mode'] ?? null) {
                    'facebook_merge' => ['provider' => 'facebook'],
                    'youtube_merge' => ['provider' => 'youtube'],
                    default => [],
                },
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
        } finally {
            app(YouTubePreparationStore::class)->release($this->plan);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Worker timeout/termination may bypass the pipeline's catch block.
        $directory = $this->plan['_directory'] ?? null;
        if (($this->plan['mode'] ?? null) === 'youtube_merge' && is_string($directory)
            && preg_match('/^[a-f0-9]{32}$/', $directory) === 1) {
            File::deleteDirectory(storage_path('app/private/downloads/'.$directory));
            app(YouTubePreparationStore::class)->release($this->plan);
            app(DownloadJobStore::class)->update($this->jobId, [
                'status' => 'failed', 'stage' => 'Failed', 'progress' => null,
                'error' => ['code' => 'preparation_timeout', 'message' => 'Preparation timed out. Analyze the URL again.'],
            ]);
        }
    }
}
