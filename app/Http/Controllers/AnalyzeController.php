<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeUrlRequest;
use App\Services\Extractor\ExtractorException;
use App\Services\MediaUrlAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AnalyzeController extends Controller
{
    public function __invoke(AnalyzeUrlRequest $request, MediaUrlAnalyzer $analyzer): JsonResponse
    {
        try {
            $data = $analyzer->analyze($request->string('url')->toString());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'url' => [$exception->getMessage()],
            ]);
        } catch (ExtractorException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->publicCode,
                    'message' => $exception->getMessage(),
                    'request_id' => $exception->requestId,
                ],
            ], $exception->httpStatus);
        }

        return response()->json(['data' => $data]);
    }
}
