<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Services\Downloads\ShareMediaPreparationStore;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ShareMediaPreparationDownloadController extends Controller
{
    public function __invoke(string $preparation, ShareMediaPreparationStore $store): BinaryFileResponse|JsonResponse
    {
        $prepared = $store->resolve($preparation);
        if ($prepared === null) {
            return response()->json(['error' => [
                'code' => 'share_preparation_expired',
                'message' => 'This file is no longer available. Analyze the URL again.',
            ]], 410);
        }

        return response()->file($prepared['path'], [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.addcslashes($prepared['filename'], '\\"').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
