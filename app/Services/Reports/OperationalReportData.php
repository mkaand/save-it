<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class OperationalReportData
{
    /** @param array<string, mixed> $summary @param array<string, mixed> $snapshot */
    public function build(array $summary, array $snapshot, int $hours, string $timezone): array
    {
        $generated = CarbonImmutable::now($timezone);
        $rows = $summary['rows'];
        $total = (int) $summary['total'];
        $failed = (int) $summary['failed'];
        $jobs = (int) $snapshot['queue']['failed_jobs'];
        $status = $jobs > 0 ? 'Review needed' : ($failed > 0 ? 'Activity with errors' : ($total > 0 ? 'No reported errors' : 'No recorded activity'));
        $labels = ['youtube' => 'YouTube', 'youtube_shorts' => 'YouTube Shorts', 'x' => 'X', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'pinterest' => 'Pinterest', 'tiktok' => 'TikTok', 'facebook' => 'Facebook'];
        $providerRows = $rows->where('provider', '!=', 'unknown');
        $providerTotal = (int) $providerRows->sum('count');
        $providers = $providerRows->groupBy('provider')->map(function (Collection $items, string $provider) use ($labels, $providerTotal): array {
            $count = (int) $items->sum('count');
            $success = (int) $items->where('successful', true)->sum('count');

            return ['label' => $labels[$provider] ?? 'Unknown / unclassified', 'count' => $count, 'success' => $success, 'errors' => $count - $success, 'rate' => $this->rate($success, $count), 'percent' => $this->percent($count, $providerTotal)];
        })->sortByDesc('count')->values()->all();

        $errors = $this->breakdown($rows->where('successful', false), 'error_code', $failed,
            fn (string $code): string => preg_match('/^[a-z0-9_-]{1,80}$/', $code) ? $code : 'unknown');
        $countries = $this->breakdown($rows, 'country_code', $total,
            fn (string $code): string => $code === 'ZZ' || ! preg_match('/^[A-Z]{2}$/', $code) ? 'ZZ · Unknown' : $code);
        // Six-hour bins for daily reports, calendar-day bins for weekly reports.
        // Only existing aggregate buckets are grouped; no visitor data is read.
        $timeline = $rows->groupBy(function ($row) use ($hours, $timezone): string {
            $date = CarbonImmutable::parse($row->bucket_start)->setTimezone($timezone);

            return $hours > 24 ? $date->format('Y-m-d') : $date->setTime(intdiv($date->hour, 6) * 6, 0)->format('Y-m-d H:i');
        })->sortKeys()->map(fn (Collection $items, string $label): array => ['label' => $label, 'count' => (int) $items->sum('count')]);
        $max = (int) ($timeline->max('count') ?? 0);
        $activity = $timeline->map(fn (array $item): array => [...$item, 'percent' => $this->percent($item['count'], $max)])->values()->all();
        $storageLabels = ['share_preparations' => 'Share preparations', 'downloads' => 'Download / job artifacts', 'recent_previews' => 'Recent Fetch previews'];
        $storage = [];
        $bytes = $files = 0;
        foreach ($storageLabels as $key => $label) {
            $item = $snapshot['storage'][$key] ?? ['count' => 0, 'size' => 0];
            $files += (int) $item['count'];
            $bytes += (int) $item['size'];
            $storage[] = ['label' => $label, 'count' => (int) $item['count'], 'size' => self::bytes((int) $item['size'])];
        }

        return [
            'period' => $hours === 168 ? 'Weekly · last 7 days' : 'Daily · last 24 hours',
            'period_start' => $generated->subHours($hours)->startOfHour()->format('M j, Y H:i'),
            'period_end' => $generated->format('M j, Y H:i'),
            'generated' => $generated->format('M j, Y H:i T'),
            'timezone' => $timezone,
            'status' => $status,
            'warning' => $jobs > 0 || $failed > 0,
            'total' => $total,
            'success' => (int) $summary['success'],
            'errors_count' => $failed,
            'success_rate' => $this->rate((int) $summary['success'], $total),
            'analyses' => (int) $rows->where('operation', 'analyze')->sum('count'),
            'downloads' => (int) $rows->where('operation', 'download')->sum('count'),
            'share_preparations' => (int) $rows->where('operation', 'share_preparation')->sum('count'),
            'rate_limits' => (int) $rows->where('operation', 'rate_limit_hit')->sum('count'),
            'providers' => $providers, 'errors' => $errors, 'countries' => $countries, 'activity' => $activity,
            'queue_depth' => (int) $snapshot['queue']['queue_depth'], 'failed_jobs' => $jobs,
            'storage' => $storage, 'total_storage' => self::bytes($bytes), 'total_files' => $files,
        ];
    }

    public static function bytes(int $bytes): string
    {
        $value = max(0, $bytes);
        foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
            if ($value < 1024 || $unit === 'TiB') {
                return ($unit === 'B' ? (string) $value : number_format($value, 1)).' '.$unit;
            }
            $value /= 1024;
        }

        return '0 B';
    }

    private function rate(int $success, int $total): string
    {
        return $total > 0 ? number_format($success * 100 / $total, 1).'%' : '—';
    }

    private function percent(int $count, int $total): int
    {
        return $total > 0 ? max(0, min(100, (int) round($count * 100 / $total))) : 0;
    }

    private function breakdown(Collection $rows, string $key, int $total, callable $label): array
    {
        return $rows->groupBy($key)->map(fn (Collection $items, string $value): array => [
            'label' => $label($value), 'count' => (int) $items->sum('count'), 'percent' => $this->percent((int) $items->sum('count'), $total),
        ])->sortByDesc('count')->take(8)->values()->all();
    }
}
