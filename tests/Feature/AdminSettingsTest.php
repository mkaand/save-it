<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyTurnstile;
use App\Models\ApplicationSetting;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\ProviderControl;
use App\Services\Settings\RuntimeConfiguration;
use App\Services\Settings\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_encrypted_and_never_rendered(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/admin/settings/email', [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'mailer',
            'password' => 'smtp-secret-value', 'from_address' => 'save@example.com', 'from_name' => 'Save It',
        ])->assertSessionHasNoErrors();
        $stored = ApplicationSetting::query()->findOrFail('mail.password');
        $this->assertTrue($stored->encrypted);
        $this->assertNotSame('smtp-secret-value', $stored->value);
        $this->actingAs($admin)->get('/admin/settings/email')->assertOk()->assertDontSee('smtp-secret-value');
        app(RuntimeConfiguration::class)->apply(app(ApplicationSettings::class));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));

        Mail::fake();
        $this->actingAs($admin)->post('/admin/settings/email/test')->assertSessionHas('status');
    }

    public function test_provider_defaults_disable_and_reenable(): void
    {
        $settings = app(ApplicationSettings::class);
        $control = app(ProviderControl::class);
        foreach (ProviderControl::PROVIDERS as $provider) {
            $this->assertTrue($control->enabled($provider));
        }
        $settings->put('provider.instagram.enabled', '0');
        $this->assertFalse($control->enabled('instagram'));
        $settings->put('provider.instagram.enabled', '1');
        $this->assertTrue($control->enabled('instagram'));
    }

    public function test_disabled_provider_returns_a_safe_controlled_response_and_can_be_reenabled(): void
    {
        Http::fake(function ($request) {
            $requestId = $request->data()['request_id'];

            return Http::response(['error' => [
                'code' => 'provider_not_implemented',
                'message' => 'Internal extractor detail.',
                'request_id' => $requestId,
                'details' => [
                    'provider' => 'x', 'provider_label' => 'X', 'provider_variant' => null,
                    'media_type' => 'unknown', 'normalized_url' => 'https://x.com/example/status/123',
                    'status' => 'not_implemented', 'metadata' => null, 'assets' => [], 'capabilities' => [],
                ],
            ]], 501);
        });
        $settings = app(ApplicationSettings::class);
        $settings->put('provider.x.enabled', '0');

        $disabled = $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'provider_disabled')
            ->assertJsonPath('error.message', 'This provider is temporarily disabled.');
        $this->assertStringNotContainsString('Internal extractor detail', $disabled->getContent());

        $settings->put('provider.x.enabled', '1');
        $this->postJson('/api/analyze', ['url' => 'https://x.com/example/status/123'])
            ->assertOk()
            ->assertJsonPath('data.platform', 'x');
    }

    public function test_rate_overrides_validate_apply_and_reset(): void
    {
        $admin = $this->admin();
        $limits = collect(config('services.abuse_limits'))->map(fn (): int => 77)->all();
        $this->actingAs($admin)->put('/admin/settings/security', ['limits' => $limits])->assertSessionHasNoErrors();
        app(RuntimeConfiguration::class)->apply(app(ApplicationSettings::class));
        $this->assertSame(77, config('services.abuse_limits.analyze'));
        $this->actingAs($admin)->put('/admin/settings/security', ['limits' => [...$limits, 'analyze' => 0]])->assertSessionHasErrors('limits.analyze');
        $this->actingAs($admin)->delete('/admin/settings/security/rate-limits')->assertSessionHas('status');
        $this->assertDatabaseMissing('application_settings', ['key' => 'rate.analyze']);
    }

    public function test_turnstile_is_disabled_by_default_and_verifies_safely(): void
    {
        $turnstile = app(Turnstile::class);
        $this->assertFalse($turnstile->active());
        $this->assertTrue($turnstile->verify(Request::create('/api/analyze', 'POST')));

        $settings = app(ApplicationSettings::class);
        $settings->put('turnstile.enabled', '1');
        $settings->put('turnstile.protect_analyze', '1');
        $settings->put('turnstile.site_key', 'site-key');
        $settings->put('turnstile.secret', 'secret-key', true);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::sequence()
                ->push(['success' => true])
                ->push(['success' => false]),
        ]);
        $request = Request::create('/api/analyze', 'POST', ['cf-turnstile-response' => 'verified-token']);
        $this->assertTrue($turnstile->verify($request));
        $this->assertFalse($turnstile->verify($request));
        $this->assertSame('Active', $turnstile->status());
        $this->assertNotSame('secret-key', ApplicationSetting::query()->findOrFail('turnstile.secret')->value);
    }

    public function test_turnstile_middleware_allows_disabled_and_verified_requests_but_rejects_failure(): void
    {
        $middleware = app(VerifyTurnstile::class);
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(200, $middleware->handle(Request::create('/api/analyze', 'POST'), $next)->getStatusCode());

        $settings = app(ApplicationSettings::class);
        $settings->put('turnstile.enabled', '1');
        $settings->put('turnstile.protect_analyze', '1');
        $settings->put('turnstile.site_key', 'site-key');
        $settings->put('turnstile.secret', 'secret-key', true);
        $request = Request::create('/api/analyze', 'POST', ['cf-turnstile-response' => 'one-time-token']);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::sequence()
                ->push(['success' => false])
                ->push(['success' => true]),
        ]);
        $this->assertSame(422, $middleware->handle($request, $next)->getStatusCode());
        $this->assertSame(200, app(VerifyTurnstile::class)->handle($request, $next)->getStatusCode());
    }

    private function admin(): User
    {
        return User::factory()->create(['username' => 'admin', 'is_admin' => true, 'password' => Hash::make('a-secure-password')]);
    }
}
