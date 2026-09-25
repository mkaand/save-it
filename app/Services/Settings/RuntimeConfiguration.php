<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Schema;

final class RuntimeConfiguration
{
    public function apply(ApplicationSettings $settings): void
    {
        if (! Schema::hasTable('application_settings')) {
            return;
        }

        foreach (array_keys(config('services.abuse_limits', [])) as $bucket) {
            $override = $settings->get("rate.{$bucket}");
            if (is_numeric($override) && (int) $override > 0) {
                config(["services.abuse_limits.{$bucket}" => (int) $override]);
            }
        }

        $host = $settings->get('mail.host');
        if (is_string($host) && $host !== '') {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => (int) $settings->get('mail.port', 587),
                // A database override must not be replaced by an environment DSN.
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.username' => $settings->get('mail.username', config('mail.mailers.smtp.username')),
                'mail.mailers.smtp.password' => $settings->get('mail.password', config('mail.mailers.smtp.password')),
                'mail.from.address' => $settings->get('mail.from_address', config('mail.from.address')),
                'mail.from.name' => $settings->get('mail.from_name', config('mail.from.name')),
            ]);
            foreach (SmtpSecurity::options($settings->get('mail.encryption')) as $key => $value) {
                config(["mail.mailers.smtp.{$key}" => $value]);
            }
        }
    }
}
