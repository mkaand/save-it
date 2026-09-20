<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    ): View {
        $emailConfigured = filled($settings->get('mail.host')) || (
            ! in_array(config('mail.default'), ['log', 'null', 'array'], true)
            && filled(config('mail.mailers.'.config('mail.default').'.host'))
        );

        return view('admin.home', [
            'admin' => $request->user(),
            'emailConfigured' => $emailConfigured,
            'enabledProviders' => collect(ProviderControl::PROVIDERS)
                ->filter(fn (string $provider): bool => $providers->enabled($provider))
                ->count(),
            'providerCount' => count(ProviderControl::PROVIDERS),
            'turnstileStatus' => $turnstile->status(),
        ]);
    }
}
