<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class AdminCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_default_admin_exists_and_bootstrap_creates_only_one_hashed_admin(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->artisan('save-it:admin:create')
            ->expectsQuestion('Username', 'admin')
            ->expectsQuestion('Email address', 'admin@example.com')
            ->expectsQuestion('Password', 'a-secure-password')
            ->expectsQuestion('Confirm password', 'a-secure-password')
            ->expectsOutput('Administrator created.')
            ->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('a-secure-password', $admin->password));
        $this->assertNotSame('a-secure-password', $admin->password);
        $this->artisan('save-it:admin:create')->expectsOutput('An administrator already exists.')->assertFailed();
    }

    public function test_bootstrap_validates_username_email_and_confirmation(): void
    {
        $this->artisan('save-it:admin:create')
            ->expectsQuestion('Username', 'x')
            ->expectsQuestion('Email address', 'not-email')
            ->expectsQuestion('Password', 'a-secure-password')
            ->expectsQuestion('Confirm password', 'different-password')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_protection_regeneration_logout_and_generic_failure(): void
    {
        $admin = $this->admin();
        $this->get('/admin')->assertRedirect('/admin/login');
        $oldId = session()->getId();
        $this->post('/admin/login', ['login' => $admin->username, 'password' => 'a-secure-password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertNotSame($oldId, session()->getId());
        $this->get('/admin/login')->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();

        $this->post('/admin/login', ['login' => 'missing', 'password' => 'wrong'])
            ->assertSessionHasErrors(['login' => 'The provided credentials are invalid.']);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['login' => 'admin', 'password' => 'wrong']);
        }
        $this->post('/admin/login', ['login' => 'admin', 'password' => 'wrong'])
            ->assertSessionHasErrors(['login' => 'Too many login attempts. Please try again later.']);
        RateLimiter::clear('admin-login:admin|127.0.0.1');
    }

    public function test_admin_mutations_require_csrf_tokens(): void
    {
        $login = app('router')->getRoutes()->getByName('admin.login.store');
        $logout = app('router')->getRoutes()->getByName('admin.logout');

        $this->assertContains('web', $login->middleware());
        $this->assertContains('web', $logout->middleware());
        $this->assertSame(['POST'], $logout->methods());
    }

    public function test_profile_and_password_updates_require_current_password(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/admin/profile', ['username' => 'operator', 'email' => 'operator@example.com'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'operator', 'email' => 'operator@example.com']);
        $this->actingAs($admin)->put('/admin/profile/password', ['current_password' => 'wrong', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('current_password');
        $this->actingAs($admin)->put('/admin/profile/password', ['current_password' => 'a-secure-password', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secure-password', $admin->fresh()->password));
    }

    public function test_password_reset_is_enumeration_safe_single_use_and_expires(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $admin = $this->admin();
        $this->post('/admin/forgot-password', ['email' => $admin->email])
            ->assertSessionHasErrors(['email' => 'Password recovery email is temporarily unavailable.']);

        config(['mail.default' => 'smtp']);
        $this->post('/admin/forgot-password', ['email' => 'unknown@example.com'])->assertSessionHas('status');
        $this->post('/admin/forgot-password', ['email' => $admin->email])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $payload = ['token' => $token, 'email' => $admin->email, 'password' => 'reset-secure-password', 'password_confirmation' => 'reset-secure-password'];
        $this->post('/admin/reset-password', $payload)->assertRedirect('/admin/login');
        $this->post('/admin/reset-password', $payload)->assertSessionHasErrors('email');

        $expired = Password::createToken($admin->fresh());
        $this->travel(61)->minutes();
        $this->post('/admin/reset-password', [...$payload, 'token' => $expired])->assertSessionHasErrors('email');
    }

    public function test_public_home_remains_available(): void
    {
        $this->get('/')->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => 'admin', 'username' => 'admin', 'email' => 'admin@example.com', 'password' => Hash::make('a-secure-password'), 'is_admin' => true]);
    }
}
