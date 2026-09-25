<?php

namespace Tests\Feature;

use App\Models\AdminReportDelivery;
use App\Models\ApplicationSetting;
use App\Models\UsageMetricBucket;
use App\Models\User;
use App\Services\Analytics\CountryResolver;
use App\Services\Analytics\UsageMetrics;
use App\Services\Reports\OperationalReportData;
use App\Services\Reports\OperationalReportService;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_geoip_trusted_header_defaults_to_a_real_value_and_requires_a_header_on_save(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/settings/geoip')
            ->assertOk()
            ->assertSee('value="CF-IPCountry"', false)
            ->assertDontSee('placeholder="CF-IPCountry"', false);
        $this->actingAs($admin)->put('/admin/settings/geoip', [
            'mode' => 'trusted_header', 'header' => '', 'maxmind_path' => '',
        ])->assertSessionHasErrors('header');
        $this->actingAs($admin)->put('/admin/settings/geoip', [
            'mode' => 'none', 'header' => '', 'maxmind_path' => '',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/admin/settings/geoip', [
            'mode' => 'maxmind', 'header' => '', 'maxmind_path' => '/missing.mmdb',
        ])->assertSessionHasNoErrors();

        $settings = app(ApplicationSettings::class);
        $settings->put('geoip.header', 'X-Country-Code');
        $this->actingAs($admin)->get('/admin/settings/geoip')->assertSee('value="X-Country-Code"', false);
    }

    public function test_analytics_reset_requires_an_admin_confirmation_and_only_deletes_metric_buckets(): void
    {
        $admin = $this->admin();
        $settings = app(ApplicationSettings::class);
        $settings->put('mail.host', 'smtp.example.test');
        $settings->put('mail.password', 'encrypted-secret', true);
        $settings->put('geoip.header', 'CF-IPCountry');
        $settings->put('reports.recipient', 'reports@example.test');
        $settings->put('provider.facebook.enabled', '0');
        $settings->put('rate.analyze', '42');
        UsageMetricBucket::query()->create([
            'bucket_start' => now()->startOfHour(), 'provider' => 'facebook', 'operation' => 'analyze',
            'successful' => true, 'error_code' => '', 'country_code' => 'TR', 'count' => 2,
        ]);

        $this->delete('/admin/analytics', ['confirmation' => 'RESET'])->assertRedirect('/admin/login');
        $this->actingAs($admin)->get('/admin/analytics')->assertOk()->assertSee('Type RESET to confirm');
        $this->actingAs($admin)->delete('/admin/analytics', ['confirmation' => 'no'])->assertSessionHasErrors('confirmation');
        $this->actingAs($admin)->delete('/admin/analytics', ['confirmation' => 'RESET'])
            ->assertRedirect('/admin/analytics')->assertSessionHas('status', 'Analytics data was reset.');

        $this->assertDatabaseCount('usage_metric_buckets', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertSame('smtp.example.test', $settings->get('mail.host'));
        $this->assertSame('encrypted-secret', $settings->get('mail.password'));
        $this->assertSame('CF-IPCountry', $settings->get('geoip.header'));
        $this->assertSame('reports@example.test', $settings->get('reports.recipient'));
        $this->assertSame('0', $settings->get('provider.facebook.enabled'));
        $this->assertSame('42', $settings->get('rate.analyze'));
        $this->assertTrue(ApplicationSetting::query()->findOrFail('mail.password')->encrypted);

        app(UsageMetrics::class)->record(Request::create('/api/analyze', 'POST'), 'analyze', true, 'facebook');
        $this->assertDatabaseCount('usage_metric_buckets', 1);
    }

    public function test_a_non_admin_cannot_reset_analytics(): void
    {
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($member)->delete('/admin/analytics', ['confirmation' => 'RESET'])
            ->assertRedirect('/admin/login');
    }

    public function test_successful_analyze_records_the_result_platform_and_provider_usage_excludes_generic_unknown_rows(): void
    {
        Http::fake(function ($request) {
            return Http::response(['data' => [
                'request_id' => $request->data()['request_id'], 'provider' => 'facebook', 'provider_label' => 'Facebook',
                'provider_variant' => 'reel', 'media_type' => 'video', 'normalized_url' => 'https://www.facebook.com/reel/123456789012',
                'status' => 'ready', 'provider_maturity' => 'available', 'warnings' => [], 'metadata' => [
                    'post_id' => '123456789012', 'text' => 'Example', 'author_name' => 'Example', 'author_handle' => null,
                    'published_at' => null, 'thumbnail_url' => 'https://scontent-ams2-1.xx.fbcdn.net/poster.jpg', 'media_count' => 1,
                ], 'assets' => [[
                    'id' => 'asset-1', 'order' => 1, 'type' => 'video', 'role' => 'primary',
                    'url' => 'https://video-ams2-1.xx.fbcdn.net/media.mp4', 'thumbnail_url' => 'https://scontent-ams2-1.xx.fbcdn.net/poster.jpg',
                    'mime_type' => 'video/mp4', 'width' => 720, 'height' => 1280, 'duration_ms' => 1000, 'alt_text' => null,
                    'variants' => [[
                        'url' => 'https://video-ams2-1.xx.fbcdn.net/media.mp4', 'mime_type' => 'video/mp4', 'protocol' => 'https',
                        'bitrate' => null, 'width' => 720, 'height' => 1280, 'fps' => null, 'container' => 'mp4',
                        'quality_label' => '720×1280', 'filesize' => null, 'is_preferred' => true,
                    ]],
                ]], 'capabilities' => ['metadata', 'media_assets', 'video_variants'],
            ]]);
        });
        $this->postJson('/api/analyze', ['url' => 'https://www.facebook.com/reel/123456789012/'])->assertOk();
        app(UsageMetrics::class)->record(Request::create('/api/share-preparations', 'POST'), 'share_preparation', true);

        $this->assertDatabaseHas('usage_metric_buckets', ['operation' => 'analyze', 'provider' => 'facebook']);
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/analytics')->assertOk()->assertSee('facebook')->assertDontSee('unknown');
        $report = app(OperationalReportData::class)->build(
            app(UsageMetrics::class)->summary(), ['queue' => ['queue_depth' => 0, 'failed_jobs' => 0], 'storage' => []], 24, 'UTC',
        );
        $this->assertSame(['Facebook'], array_column($report['providers'], 'label'));
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
