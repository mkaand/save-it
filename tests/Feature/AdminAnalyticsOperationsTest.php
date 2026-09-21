<?php

namespace Tests\Feature;

use App\Models\AdminReportDelivery;
use App\Models\UsageMetricBucket;
use App\Models\User;
use App\Services\Analytics\CountryResolver;
use App\Services\Analytics\UsageMetrics;
use App\Services\Reports\OperationalReportService;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AdminAnalyticsOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_are_aggregate_and_do_not_store_raw_request_data(): void
    {
        $request = Request::create('/api/analyze?url=https://private.example/token', 'POST', ['url' => 'https://private.example/token'], [], [], ['REMOTE_ADDR' => '203.0.113.4']);
        app(UsageMetrics::class)->record($request, 'analyze', true, 'instagram');
        app(UsageMetrics::class)->record($request, 'analyze', false, 'instagram', 'provider_timeout');

        $this->assertDatabaseCount('usage_metric_buckets', 2);
        $row = UsageMetricBucket::query()->firstOrFail();
        $this->assertSame('instagram', $row->provider);
        $this->assertSame('ZZ', $row->country_code);
        $this->assertDatabaseMissing('usage_metric_buckets', ['provider' => 'private.example']);
        $this->assertFalse(array_key_exists('ip', $row->getAttributes()));
        $this->assertFalse(array_key_exists('url', $row->getAttributes()));
    }

    public function test_country_resolver_is_opt_in_and_validates_trusted_header(): void
    {
        $settings = app(ApplicationSettings::class);
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => 'tr']);
        $resolver = app(CountryResolver::class);
        $this->assertSame('ZZ', $resolver->resolve($request));
        $settings->put('geoip.mode', 'trusted_header');
        $settings->put('geoip.header', 'CF-IPCountry');
        $this->assertSame('TR', $resolver->resolve($request));
        $request->headers->set('CF-IPCountry', 'not-a-country');
        $this->assertSame('ZZ', $resolver->resolve($request));
        $settings->put('geoip.mode', 'maxmind');
        $settings->put('geoip.maxmind_path', '/missing/GeoLite2-Country.mmdb');
        $this->assertSame('ZZ', $resolver->resolve($request));
    }

    public function test_dashboard_analytics_operations_geoip_and_reports_are_admin_protected(): void
    {
        $admin = $this->admin();
        foreach (['/admin/analytics', '/admin/operations', '/admin/reports', '/admin/settings/geoip'] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        foreach (['/admin/analytics', '/admin/operations', '/admin/reports', '/admin/settings/geoip'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Operations dashboard')->assertSee('Pinterest');
        $this->actingAs($admin)->put('/admin/settings/geoip', ['mode' => 'trusted_header', 'header' => 'CF-IPCountry', 'maxmind_path' => ''])->assertSessionHasNoErrors();
    }

    public function test_report_modes_send_now_safely_and_prevent_duplicate_scheduled_periods(): void
    {
        $admin = $this->admin();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'localhost']);
        $this->actingAs($admin)->put('/admin/reports', ['mode' => 'daily', 'recipient' => $admin->email, 'timezone' => 'UTC', 'time' => now('UTC')->format('H:i'), 'weekday' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/reports/send')->assertSessionHas('status');
        app(OperationalReportService::class)->sendDue();
        app(OperationalReportService::class)->sendDue();
        $this->assertDatabaseCount('admin_report_deliveries', 1);
        $this->assertInstanceOf(AdminReportDelivery::class, AdminReportDelivery::query()->first());
    }

    public function test_reports_are_safe_when_mail_is_not_configured(): void
    {
        config(['mail.default' => 'log']);
        $this->assertFalse(app(OperationalReportService::class)->sendNow('admin@example.com'));
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => 'admin', 'username' => 'admin', 'email' => 'admin@example.com', 'password' => Hash::make('a-secure-password'), 'is_admin' => true]);
    }
}
