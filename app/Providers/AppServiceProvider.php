<?php

namespace App\Providers;

use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\RuntimeConfiguration;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (Schema::hasTable('application_settings')) {
                app(RuntimeConfiguration::class)->apply(app(ApplicationSettings::class));
            }
        } catch (\Throwable) {
            // Fresh installs must still be able to run migrations and console commands.
        }
        ResetPassword::createUrlUsing(fn ($user, string $token): string => route('admin.password.reset', ['token' => $token, 'email' => $user->email]));
        RateLimiter::for('analyze', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('downloads', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
