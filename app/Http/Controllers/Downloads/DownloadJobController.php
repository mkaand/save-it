<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareDownloadJob;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\DownloadJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DownloadJobController extends Controller
{
    public function store(
        Request $request,
        DownloadAssetStore $tokens,
        DownloadJobStore $jobs,
    ): JsonResponse {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:160'],
        ]);

        try {
            $plan = $tokens->consume($validated['token']);
            if (! in_array($plan['mode'] ?? null, ['youtube_merge', 'youtube_mp3', 'zip'], true)) {
                throw new DownloadException(
                    'invalid_download_token',
                    410,
                    'This download link is no longer valid. Analyze the URL again.',
                );
            }
            $jobId = $jobs->create(['mode' => $plan['mode']]);
            PrepareDownloadJob::dispatch($jobId, $plan);

            return response()->json([
                'data' => [
                    'id' => $jobId,
                    'status' => 'queued',
                    'stage' => 'Preparing',
                    'progress' => 0,
                    'status_url' => route('api.download-jobs.show', ['job' => $jobId], false),
                ],
            ], 202);
        } catch (DownloadException $exception) {
            return $this->error($exception);
        }
    }

    public function show(string $job, DownloadJobStore $jobs): JsonResponse
    {
        try {
            $state = $jobs->get($job);

            return response()->json(['data' => [
                'id' => $job,
                'status' => $state['status'],
                'stage' => $state['stage'],
                'progress' => $state['progress'],
                'download_url' => $state['download_url'] ?? null,
                'size' => $state['size'] ?? null,
                'error' => $state['error'] ?? null,
            ]]);
        } catch (DownloadException $exception) {
            return $this->error($exception);
        }
    }

    private function error(DownloadException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ],
        ], $exception->httpStatus, $exception->headers);
    }
}
