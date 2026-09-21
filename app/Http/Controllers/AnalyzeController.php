<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeUrlRequest;
use App\Services\Analytics\UsageMetrics;
use App\Services\Extractor\ExtractorException;
use App\Services\MediaUrlAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AnalyzeController extends Controller
{
    public function __invoke(AnalyzeUrlRequest $request, MediaUrlAnalyzer $analyzer, UsageMetrics $metrics): JsonResponse
    {
        try {
            $data = $analyzer->analyze($request->string('url')->toString());
        } catch (InvalidArgumentException $exception) {
            $metrics->record($request, 'analyze', false, 'unknown', 'invalid_url');
            throw ValidationException::withMessages([
                'url' => [$exception->getMessage()],
            ]);
        } catch (ExtractorException $exception) {
            $metrics->record($request, 'analyze', false, 'unknown', $exception->publicCode);

            return response()->json([
                'error' => [
                    'code' => $exception->publicCode,
                    'message' => $exception->getMessage(),
                    'request_id' => $exception->requestId,
                ],
            ], $exception->httpStatus);
        }

        $metrics->record($request, 'analyze', true, is_string($data['provider'] ?? null) ? $data['provider'] : 'unknown');

        return response()->json(['data' => $data]);
    }
}
