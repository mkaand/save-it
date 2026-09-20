<?php

namespace App\Services\Settings;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Crypt;

final class ApplicationSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $setting = ApplicationSetting::query()->find($key);
            if ($setting === null || $setting->value === null) {
                return $default;
            }

            return $setting->encrypted ? Crypt::decryptString($setting->value) : $setting->value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public function put(string $key, mixed $value, bool $encrypted = false): void
    {
        ApplicationSetting::query()->updateOrCreate(['key' => $key], [
            'value' => $encrypted && $value !== null ? Crypt::encryptString((string) $value) : $value,
            'encrypted' => $encrypted,
        ]);
    }

    public function forget(string $key): void
    {
        ApplicationSetting::query()->whereKey($key)->delete();
    }

    /** @return array<string, mixed> */
    public function many(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => $this->get($key)])->all();
    }
}
