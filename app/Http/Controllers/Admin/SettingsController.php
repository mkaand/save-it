<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\MailFailureReporter;
use App\Services\Settings\ProviderControl;
use App\Services\Settings\SmtpSecurity;
use App\Services\Settings\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

final class SettingsController extends Controller
{
    public function providers(ApplicationSettings $settings): View
    {
        return view('admin.providers', ['providers' => collect(ProviderControl::PROVIDERS)->mapWithKeys(fn (string $provider): array => [$provider => filter_var($settings->get("provider.{$provider}.enabled", '1'), FILTER_VALIDATE_BOOL)])]);
    }

    public function updateProviders(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        foreach (ProviderControl::PROVIDERS as $provider) {
            $settings->put("provider.{$provider}.enabled", $request->boolean($provider) ? '1' : '0');
        }

        return back()->with('status', 'Provider settings updated.');
    }

    public function email(ApplicationSettings $settings): View
    {
        $mail = $settings->many(['mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.from_address', 'mail.from_name']);
        $mail['mail.encryption'] = filled($mail['mail.host']) ? SmtpSecurity::mode($mail['mail.encryption']) : 'starttls';
        $configured = filled($mail['mail.host']) || (
            ! in_array(config('mail.default'), ['log', 'null', 'array'], true)
            && filled(config('mail.mailers.'.config('mail.default').'.host'))
        );

        return view('admin.email', compact('mail', 'configured'));
    }

    public function updateEmail(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/'], 'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:starttls,smtps,plain,legacy_auto,tls,ssl,none'], 'username' => ['nullable', 'string', 'max:254', 'not_regex:/[\r\n]/'],
            'password' => ['nullable', 'string', 'max:1024'], 'from_address' => ['required', 'email:rfc', 'max:254'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);
        foreach (['host', 'port', 'encryption', 'username', 'from_address', 'from_name'] as $key) {
            $settings->put("mail.{$key}", $data[$key] ?? null);
        }
        if (filled($data['password'] ?? null)) {
            $settings->put('mail.password', $data['password'], true);
        }

        return back()->with('status', 'Email settings updated.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        try {
            Mail::raw('Save It email configuration test.', fn ($message) => $message->to($request->user()->email)->subject('Save It test email'));

            return back()->with('status', 'Test email sent.');
        } catch (\Throwable $exception) {
            MailFailureReporter::report('test_email', $exception);

            return back()->withErrors(['email' => 'The test email could not be sent.']);
        }
    }

    public function security(ApplicationSettings $settings, Turnstile $turnstile): View
    {
        $defaults = config('services.abuse_limit_defaults');

        return view('admin.security', [
            'limits' => collect($defaults)->mapWithKeys(fn ($value, $key): array => [$key => (int) $settings->get("rate.{$key}", $value)]),
            'defaults' => $defaults,
            'turnstile' => [
                'enabled' => $turnstile->enabled(),
                'protect' => $turnstile->protectsAnalyze(),
                'site_key' => $turnstile->siteKey(),
                'has_secret' => $turnstile->hasSecret(),
                'status' => $turnstile->status(),
            ],
        ]);
    }

    public function updateSecurity(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $keys = array_keys(config('services.abuse_limits'));
        $rules = collect($keys)->mapWithKeys(fn (string $key): array => ["limits.{$key}" => ['required', 'integer', 'between:1,10000']])->all();
        $data = $request->validate([...$rules, 'site_key' => ['nullable', 'string', 'max:200'], 'secret' => ['nullable', 'string', 'max:300']]);
        foreach ($keys as $key) {
            $settings->put("rate.{$key}", (string) $data['limits'][$key]);
        }
        $settings->put('turnstile.enabled', $request->boolean('turnstile_enabled') ? '1' : '0');
        $settings->put('turnstile.protect_analyze', $request->boolean('protect_analyze') ? '1' : '0');
        $settings->put('turnstile.site_key', $data['site_key'] ?? '');
        if (filled($data['secret'] ?? null)) {
            $settings->put('turnstile.secret', $data['secret'], true);
        }

        return back()->with('status', 'Security settings updated.');
    }

    public function resetLimits(ApplicationSettings $settings): RedirectResponse
    {
        foreach (array_keys(config('services.abuse_limits')) as $key) {
            $settings->forget("rate.{$key}");
        }

        return back()->with('status', 'Rate limits reset to defaults.');
    }

    public function testTurnstile(ApplicationSettings $settings): RedirectResponse
    {
        $secret = (string) $settings->get('turnstile.secret', config('services.turnstile.secret_key', ''));
        if ($secret === '') {
            return back()->withErrors(['turnstile' => 'Turnstile is not configured.']);
        }
        try {
            $codes = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => ''])->json('error-codes', []);
            $valid = is_array($codes) && ! in_array('invalid-input-secret', $codes, true);

            return $valid ? back()->with('status', 'Turnstile credentials accepted.') : back()->withErrors(['turnstile' => 'Turnstile configuration error.']);
        } catch (\Throwable) {
            return back()->withErrors(['turnstile' => 'Turnstile configuration could not be tested.']);
        }
    }

    public function geoip(ApplicationSettings $settings): View
    {
        return view('admin.geoip', ['geoip' => [
            'mode' => $settings->get('geoip.mode', 'none'),
            'header' => $settings->get('geoip.header', ''),
            'maxmind_path' => $settings->get('geoip.maxmind_path', ''),
        ]]);
    }

    public function updateGeoip(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:none,trusted_header,maxmind'],
            'header' => ['nullable', 'regex:/^[A-Za-z0-9-]{1,100}$/'],
            'maxmind_path' => ['nullable', 'string', 'max:1024'],
        ]);
        $settings->put('geoip.mode', $data['mode']);
        $settings->put('geoip.header', $data['header'] ?? '');
        $settings->put('geoip.maxmind_path', $data['maxmind_path'] ?? '');

        return back()->with('status', 'GeoIP settings updated.');
    }
}
