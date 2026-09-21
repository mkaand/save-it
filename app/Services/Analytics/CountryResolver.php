<?php

namespace App\Services\Analytics;

use App\Services\Settings\ApplicationSettings;
use GeoIp2\Database\Reader;
use Illuminate\Http\Request;

final class CountryResolver
{
    public function resolve(Request $request): string
    {
        $settings = app(ApplicationSettings::class);
        $mode = (string) $settings->get('geoip.mode', 'none');
        if ($mode === 'trusted_header') {
            $header = (string) $settings->get('geoip.header', '');
            $value = $header === '' ? null : $request->header($header);

            return $this->country($value);
        }
        if ($mode === 'maxmind') {
            $path = (string) $settings->get('geoip.maxmind_path', '');
            if ($path !== '' && is_file($path) && class_exists(Reader::class)) {
                try {
                    $reader = new Reader($path);
                    $country = $reader->country((string) $request->ip())->country->isoCode;
                    $reader->close();

                    return $this->country($country);
                } catch (\Throwable) {
                    return 'ZZ';
                }
            }
        }

        return 'ZZ';
    }

    private function country(mixed $value): string
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : 'ZZ';
    }
}
