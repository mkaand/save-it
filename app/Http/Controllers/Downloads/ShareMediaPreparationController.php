<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareShareMediaJob;
use App\Services\Analytics\UsageMetrics;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\ShareMediaPreparationJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShareMediaPreparationController extends Controller
{
    public function __invoke(
        Request $request,
        DownloadAssetStore $downloads,
        ShareMediaPreparationJobStore $jobs,
        UsageMetrics $metrics,
    ): JsonResponse {
        $token = $request->input('token');
        if (! is_string($token)) {
            $metrics->record($request, 'share_preparation', false, 'unknown', 'share_unavailable');

            return $this->error(new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.'));
        }

        try {
            $asset = $downloads->consume($token);
            if (($asset['mime_type'] ?? null) !== 'video/mp4' || ! in_array($asset['mode'] ?? null, ['local_file', 'proxy', 'youtube_direct'], true)) {
                throw new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.');
            }
            $jobId = $jobs->create($asset);
            PrepareShareMediaJob::dispatch($jobId);
            $metrics->record($request, 'share_preparation', true, is_string($asset['provider'] ?? null) ? $asset['provider'] : 'unknown');

            return response()->json(['data' => [
                'id' => $jobId,
                'status' => 'queued',
                'stage' => 'Queued',
                'progress' => null,
                'status_url' => route('api.share-preparation-jobs.show', ['job' => $jobId], false),
            ]], 202);
        } catch (DownloadException $exception) {
            $metrics->record($request, 'share_preparation', false, 'unknown', $exception->publicCode);

            return $this->error($exception);
        } catch (\Throwable) {
            $metrics->record($request, 'share_preparation', false, 'unknown', 'share_unavailable');

            return $this->error(new DownloadException('share_unavailable', 503, 'This file cannot be prepared for sharing.'));
        }
    }

    private function error(DownloadException $exception): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $exception->publicCode,
            'message' => $exception->getMessage(),
        ]], $exception->httpStatus, $exception->headers);
    }
}
