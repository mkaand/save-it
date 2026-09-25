<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\UsageMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AnalyticsController extends Controller
{
    public function __invoke(Request $request, UsageMetrics $metrics): View
    {
        $range = (int) $request->integer('range', 168);
        $range = in_array($range, [24, 168, 720], true) ? $range : 168;
        $summary = $metrics->summary($range);
        $rows = $summary['rows'];
        // Provider usage is intentionally provider-attributable only. Generic
        // operational events remain in total activity/error counters.
        $providerRows = $rows->reject(fn ($row): bool => $row->provider === 'unknown');

        return view('admin.analytics', [
            'range' => $range,
            'summary' => $summary,
            'timeline' => $rows->groupBy(fn ($row) => $row->bucket_start->format('M j H:i'))->map(fn ($items) => [
                'total' => $items->sum('count'), 'success' => $items->where('successful', true)->sum('count'),
            ])->all(),
            'providers' => $providerRows->groupBy('provider')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'errorBreakdown' => $rows->where('successful', false)->groupBy('error_code')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'countries' => $rows->groupBy('country_code')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'downloads' => $rows->where('operation', 'download')->groupBy(fn ($row) => $row->bucket_start->format('M j H:i'))->map(fn ($items) => $items->sum('count'))->all(),
        ]);
    }

    public function reset(UsageMetrics $metrics): RedirectResponse
    {
        try {
            $metrics->reset();

            return redirect()->route('admin.analytics')->with('status', 'Analytics data was reset.');
        } catch (\Throwable) {
            return back()->withErrors(['analytics' => 'Analytics data could not be reset.']);
        }
    }
}
