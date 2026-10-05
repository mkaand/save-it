<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\ShareMediaPreparationJobStore;
use Illuminate\Http\JsonResponse;

final class ShareMediaPreparationJobController extends Controller
{
    public function __invoke(string $job, ShareMediaPreparationJobStore $jobs): JsonResponse
    {
        try {
            $state = $jobs->get($job);

            return response()->json(['data' => [
                'id' => $job,
                'status' => $state['status'],
                'stage' => $state['stage'],
                'progress' => is_int($state['progress'] ?? null) ? $state['progress'] : null,
                'url' => $state['status'] === 'ready' ? ($state['prepared_url'] ?? null) : null,
                'filename' => $state['status'] === 'ready' ? ($state['filename'] ?? null) : null,
                'mime_type' => $state['status'] === 'ready' ? ($state['mime_type'] ?? null) : null,
                'error' => $state['status'] === 'failed' ? ($state['error'] ?? null) : null,
            ]]);
        } catch (DownloadException $exception) {
            return response()->json(['error' => [
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ]], $exception->httpStatus, $exception->headers);
        }
    }
}
