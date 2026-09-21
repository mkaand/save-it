<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\UsageMetrics;
use App\Services\Operations\OperationsService;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\ProviderControl;
use App\Services\Settings\Turnstile;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicationSettings $settings,
        ProviderControl $providers,
        Turnstile $turnstile,
        UsageMetrics $metrics,
        OperationsService $operations,
    ): View {
        $emailConfigured = filled($settings->get('mail.host')) || (
            ! in_array(config('mail.default'), ['log', 'null', 'array'], true)
            && filled(config('mail.mailers.'.config('mail.default').'.host'))
        );

        $month = $metrics->summary(720);
        $sevenDays = $metrics->summary(168);
        $today = $metrics->summary(24);
        $rows = $month['rows'];
        $providerStatus = collect(ProviderControl::PROVIDERS)->mapWithKeys(function (string $provider) use ($rows, $providers): array {
            $providerRows = $rows->where('provider', $provider);
            $success = $providerRows->where('successful', true)->sum('count');
            $failed = $providerRows->where('successful', false)->sum('count');
            $lastSuccess = $providerRows->where('successful', true)->sortByDesc('bucket_start')->first();
            $lastError = $providerRows->where('successful', false)->sortByDesc('bucket_start')->first();

            return [$provider => [
                'enabled' => $providers->enabled($provider), 'success' => $success, 'failed' => $failed,
                'last_success' => $lastSuccess?->bucket_start, 'last_error' => $lastError?->error_code,
                'success_rate' => $success + $failed > 0 ? (int) round($success * 100 / ($success + $failed)) : null,
            ]];
        });

        return view('admin.home', [
            'admin' => $request->user(),
            'emailConfigured' => $emailConfigured,
            'enabledProviders' => collect(ProviderControl::PROVIDERS)
                ->filter(fn (string $provider): bool => $providers->enabled($provider))
                ->count(),
            'providerCount' => count(ProviderControl::PROVIDERS),
            'turnstileStatus' => $turnstile->status(),
            'metrics' => [
                'today' => $today, 'sevenDays' => $sevenDays, 'month' => $month,
                'analysesToday' => $today['rows']->where('operation', 'analyze')->sum('count'),
                'analysesSevenDays' => $sevenDays['rows']->where('operation', 'analyze')->sum('count'),
                'analysesMonth' => $month['rows']->where('operation', 'analyze')->sum('count'),
                'failedAnalyses' => $month['rows']->where('operation', 'analyze')->where('successful', false)->sum('count'),
                'downloads' => $month['rows']->where('operation', 'download')->sum('count'),
                'shares' => $month['rows']->where('operation', 'share_preparation')->sum('count'),
                'rateLimits' => $month['rows']->where('operation', 'rate_limit_hit')->sum('count'),
            ],
            'providerStatus' => $providerStatus,
            'operations' => $operations->snapshot(),
        ]);
    }
}
