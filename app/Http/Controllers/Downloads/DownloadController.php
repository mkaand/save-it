<?php

namespace App\Http\Controllers\Downloads;

use App\Http\Controllers\Controller;
use App\Services\Downloads\DownloadAssetStore;
use App\Services\Downloads\DownloadException;
use App\Services\Downloads\MediaStreamService;
use App\Services\Downloads\YouTubeSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadController extends Controller
{
    public function __invoke(
        string $token,
        Request $request,
        DownloadAssetStore $store,
        MediaStreamService $streamer,
        YouTubeSourceResolver $youtube,
    ): StreamedResponse|BinaryFileResponse|JsonResponse {
        try {
            $asset = $store->resolve($token);
            if (($asset['mode'] ?? null) === 'local_file') {
                $path = $asset['path'] ?? null;
                $root = realpath(storage_path('app/private/downloads'));
                $realPath = is_string($path) ? realpath($path) : false;
                if (
                    $root === false
                    || $realPath === false
                    || ! str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
                    || ! is_file($realPath)
                ) {
                    throw new DownloadException(
                        'download_job_expired',
                        410,
                        'This prepared download is no longer available.',
                    );
                }

                return response()
                    ->download(
                        $realPath,
                        is_string($asset['filename'] ?? null) ? $asset['filename'] : 'media',
                        [
                            'Content-Type' => is_string($asset['mime_type'] ?? null)
                                ? $asset['mime_type']
                                : 'application/octet-stream',
                            'Cache-Control' => 'private, no-store',
                            'X-Content-Type-Options' => 'nosniff',
                            'X-Accel-Buffering' => 'no',
                        ],
                    )
                    ->deleteFileAfterSend(true);
            } elseif (($asset['mode'] ?? null) === 'youtube_direct') {
                $asset = $youtube->resolve($asset);
            } elseif (($asset['mode'] ?? null) !== 'proxy') {
                throw new DownloadException(
                    'download_requires_job',
                    409,
                    'This output must be prepared before it can be downloaded.',
                );
            }

            return $streamer->stream($asset, $request->header('Range'));
        } catch (DownloadException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->publicCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus, $exception->headers);
        }
    }
}
