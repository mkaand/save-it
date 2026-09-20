<?php

namespace App\Services\Settings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class Turnstile
{
    public function enabled(): bool
    {
        return filter_var(
            app(ApplicationSettings::class)->get('turnstile.enabled', config('services.turnstile.enabled', false)),
            FILTER_VALIDATE_BOOL,
        );
    }

    public function protectsAnalyze(): bool
    {
        return filter_var(
            app(ApplicationSettings::class)->get('turnstile.protect_analyze', config('services.turnstile.protect_analyze', false)),
            FILTER_VALIDATE_BOOL,
        );
    }

    public function configured(): bool
    {
        return $this->siteKey() !== '' && $this->secret() !== '';
    }

    public function active(): bool
    {
        return $this->enabled() && $this->protectsAnalyze() && $this->configured();
    }

    public function status(): string
    {
        if (! $this->enabled()) {
            return 'Disabled';
        }
        if (! $this->configured()) {
            return 'Configuration error';
        }

        return $this->protectsAnalyze() ? 'Active' : 'Configured';
    }

    public function siteKey(): string
    {
        return (string) app(ApplicationSettings::class)->get('turnstile.site_key', config('services.turnstile.site_key', ''));
    }

    public function verify(Request $request): bool
    {
        if (! $this->active()) {
            return true;
        }
        $response = $request->input('cf-turnstile-response');
        if (! is_string($response) || $response === '') {
            return false;
        }

        try {
            return Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $this->secret(),
                'response' => $response,
                'remoteip' => $request->ip(),
            ])->json('success') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== '';
    }

    private function secret(): string
    {
        return (string) app(ApplicationSettings::class)->get('turnstile.secret', config('services.turnstile.secret_key', ''));
    }
}
