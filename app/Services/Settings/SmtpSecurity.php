<?php

namespace App\Services\Settings;

final class SmtpSecurity
{
    public static function mode(mixed $value): string
    {
        return match ($value) {
            'tls', 'starttls' => 'starttls',
            'ssl', 'smtps' => 'smtps',
            'plain' => 'plain',
            // Old None/null meant Symfony's opportunistic STARTTLS, not plaintext.
            null, '', 'none', 'legacy_auto' => 'legacy_auto',
            default => 'starttls',
        };
    }

    /** @return array<string, mixed> */
    public static function options(mixed $value): array
    {
        $mode = self::mode($value);

        return [
            'transport' => $mode === 'starttls' ? 'required_starttls' : 'smtp',
            'scheme' => $mode === 'smtps' ? 'smtps' : 'smtp',
            'auto_tls' => $mode !== 'plain',
            'require_tls' => in_array($mode, ['starttls', 'smtps'], true),
            'verify_peer' => true,
        ];
    }
}
