<?php

namespace App\Services\Analytics;

use App\Models\UsageMetricBucket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class UsageMetrics
{
    public function record(Request $request, string $operation, bool $successful, string $provider = 'unknown', ?string $errorCode = null): void
    {
        try {
            if (! Schema::hasTable('usage_metric_buckets')) {
                return;
            }
            $values = [
                'bucket_start' => now()->startOfHour()->toDateTimeString(),
                'provider' => $this->safe($provider, 32),
                'operation' => $this->safe($operation, 32),
                'successful' => $successful,
                'error_code' => $this->safe($errorCode ?? '', 80),
                'country_code' => app(CountryResolver::class)->resolve($request),
            ];
            UsageMetricBucket::query()->firstOrCreate($values, ['count' => 0])->increment('count');
        } catch (\Throwable) {
            // Metrics must never make anonymous application traffic unavailable.
        }
    }

    /** @return array<string, mixed> */
    public function summary(int $hours = 24): array
    {
        $since = now()->subHours($hours)->startOfHour();
        $rows = UsageMetricBucket::query()->where('bucket_start', '>=', $since)->get();
        $total = $rows->sum('count');
        $success = $rows->where('successful', true)->sum('count');

        return ['rows' => $rows, 'total' => $total, 'success' => $success, 'failed' => $total - $success];
    }

    /**
     * Delete only privacy-first aggregate telemetry. Configuration, reports,
     * queues and application-managed media are deliberately outside this scope.
     */
    public function reset(): void
    {
        try {
            if (Schema::hasTable('usage_metric_buckets')) {
                UsageMetricBucket::query()->delete();
            }
            Log::info('admin_analytics_reset');
        } catch (\Throwable $exception) {
            Log::warning('admin_analytics_reset_failed', ['exception' => $exception::class]);
            throw $exception;
        }
    }

    private function safe(string $value, int $limit): string
    {
        $value = strtolower(preg_replace('/[^a-z0-9_-]/', '', $value) ?? '');

        return substr($value, 0, $limit) ?: 'unknown';
    }
}
