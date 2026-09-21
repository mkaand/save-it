<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            Cache::store(config('cache.limiter'))->get('save-it:readiness:probe');
        } catch (\Throwable) {
            return response()->json(['status' => 'not_ready'], 503);
        }

        return response()->json([
            'status' => 'ready',
            'application' => config('app.name'),
        ]);
    }
}
