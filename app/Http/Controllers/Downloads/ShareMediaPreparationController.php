<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Services\Analytics\UsageMetrics;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\ShareMediaPreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShareMediaPreparationController extends Controller
{
    public function __invoke(Request $request, ShareMediaPreparationService $preparations, UsageMetrics $metrics): JsonResponse
    {
        $token = $request->input('token');
        if (! is_string($token)) {
            $metrics->record($request, 'share_preparation', false, 'unknown', 'share_unavailable');

            return $this->error(new DownloadException('share_unavailable', 422, 'This file cannot be prepared for sharing.'));
        }

        try {
            $prepared = $preparations->prepare($token);
            $metrics->record($request, 'share_preparation', true, $prepared['provider']);

            return response()->json(['data' => [
                'url' => route('api.share-preparations.show', ['preparation' => $prepared['id']], false),
                'filename' => $prepared['filename'],
                'mime_type' => 'video/mp4',
            ]]);
        } catch (DownloadException $exception) {
            $metrics->record($request, 'share_preparation', false, 'unknown', $exception->publicCode);

            return $this->error($exception);
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
