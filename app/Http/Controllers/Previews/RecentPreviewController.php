<?php

namespace App\Http\Controllers\Previews;

use App\Http\Controllers\Controller;
use App\Services\Previews\RecentPreviewStore;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class RecentPreviewController extends Controller
{
    public function __invoke(
        string $preview,
        RecentPreviewStore $store,
    ): BinaryFileResponse|JsonResponse {
        $asset = $store->resolve($preview);

        if ($asset === null) {
            return response()->json([
                'error' => ['message' => 'This preview is no longer available. Analyze the URL again.'],
            ], 410);
        }

        return response()->file($asset['path'], [
            'Content-Type' => $asset['mime_type'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
