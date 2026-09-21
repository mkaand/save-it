<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_and_readiness_have_separate_safe_contracts(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok', 'application' => 'Save It']);
        $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready', 'application' => 'Save It'])->assertHeaderMissing('Set-Cookie');
    }

    public function test_readiness_fails_closed_when_shared_cache_is_unavailable(): void
    {
        Cache::shouldReceive('store')->once()->andThrow(new \RuntimeException('unavailable'));

        $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready']);
    }

    public function test_public_and_authenticated_admin_acceptance_routes_render(): void
    {
        $this->get('/')->assertOk()->assertSee('data-analyzer-form', false);

        $admin = User::factory()->create(['username' => 'admin', 'is_admin' => true, 'password' => Hash::make('secret-password')]);
        foreach (['/admin', '/admin/analytics', '/admin/operations', '/admin/reports', '/admin/settings/geoip', '/admin/profile', '/admin/settings/providers', '/admin/settings/email', '/admin/settings/security'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_public_controlled_provider_error_is_safe_for_browser_rendering(): void
    {
        Http::fake(['http://extractor:8000/*' => Http::response([
            'error' => ['code' => 'upstream_unavailable', 'message' => 'Internal provider detail', 'request_id' => 'fixture'],
        ], 503)]);

        $this->postJson('/api/analyze', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'upstream_unavailable')
            ->assertJsonPath('error.message', 'The analysis service is temporarily unavailable.')
            ->assertDontSee('Internal provider detail');
    }
}
