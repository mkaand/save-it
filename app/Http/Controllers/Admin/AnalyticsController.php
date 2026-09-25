<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\UsageMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

final class AnalyticsController extends Controller
{
    public function __invoke(Request $request, UsageMetrics $metrics): View
    {
        $range = (int) $request->integer('range', 168);
        $range = in_array($range, [24, 168, 720], true) ? $range : 168;
        $summary = $metrics->summary($range);
        $rows = $summary['rows'];

        return view('admin.analytics', [
            'range' => $range,
            'summary' => $summary,
            'timeline' => $rows->groupBy(fn ($row) => $row->bucket_start->format('M j H:i'))->map(fn ($items) => [
                'total' => $items->sum('count'), 'success' => $items->where('successful', true)->sum('count'),
            ])->all(),
            'providers' => $rows->where('provider', '!=', 'unknown')->groupBy('provider')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'errorBreakdown' => $rows->where('successful', false)->groupBy('error_code')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'countries' => $rows->groupBy('country_code')->map(fn ($items) => $items->sum('count'))->sortDesc(),
            'downloads' => $rows->where('operation', 'download')->groupBy(fn ($row) => $row->bucket_start->format('M j H:i'))->map(fn ($items) => $items->sum('count'))->all(),
        ]);
    }

    public function reset(Request $request, UsageMetrics $metrics): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:RESET']]);

        try {
            $metrics->reset();

            return redirect()->route('admin.analytics')->with('status', 'Analytics data was reset.');
        } catch (\Throwable $exception) {
            Log::warning('Admin analytics reset failed.', ['exception' => $exception::class]);

            return back()->withErrors(['analytics' => 'Analytics data could not be reset.']);
        }
    }
}
