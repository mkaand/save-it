<?php

namespace App\Http\Controllers\Previews;

use App\Http\Controllers\Controller;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\MediaStreamService;
use App\Services\Previews\RecentPreviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecentPreviewController extends Controller
{
    public function __invoke(
        string $preview,
        Request $request,
        RecentPreviewStore $store,
        MediaStreamService $streamer,
    ): StreamedResponse|JsonResponse {
        $asset = $store->resolve($preview);

        if ($asset === null) {
            return response()->json([
                'error' => ['message' => 'This preview is no longer available. Analyze the URL again.'],
            ], 410);
        }

        try {
            return $streamer->stream($asset, $request->header('Range'));
        } catch (DownloadException $exception) {
            return response()->json([
                'error' => ['message' => 'This preview could not be loaded.'],
            ], $exception->httpStatus, $exception->headers);
        }
    }
}
