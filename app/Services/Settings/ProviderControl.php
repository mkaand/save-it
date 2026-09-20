<?php

namespace App\Services\Settings;

final class ProviderControl
{
    public const PROVIDERS = ['youtube', 'x', 'instagram', 'linkedin'];

    public function enabled(string $provider): bool
    {
        return filter_var(app(ApplicationSettings::class)->get("provider.{$provider}.enabled", '1'), FILTER_VALIDATE_BOOL);
    }
}
