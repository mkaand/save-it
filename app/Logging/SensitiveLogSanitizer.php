<?php

namespace App\Logging;

use Monolog\LogRecord;

final class SensitiveLogSanitizer
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->string($record->message),
            context: $this->value($record->context),
            extra: $this->value($record->extra),
        );
    }

    private function value(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[$key] = $this->sensitiveKey((string) $key) ? '[REDACTED]' : $this->value($item);
            }

            return $safe;
        }

        return is_string($value) ? $this->string($value) : $value;
    }

    private function sensitiveKey(string $key): bool
    {
        return preg_match('/authorization|cookie|secret|password|token|turnstile|api[_-]?key/i', $key) === 1;
    }

    private function string(string $value): string
    {
        $value = preg_replace('/(Authorization|Cookie)\s*[:=].*$/i', '$1: [REDACTED]', $value) ?? $value;
        $value = preg_replace('/([?&](?:token|sig|signature|expires|st|se|sp|api[_-]?key)=[^&#\s]+)/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/\/api\/(?:downloads|share-preparations)\/[a-z0-9]{48}(?:\.[a-f0-9]{64})?/i', '/api/[REDACTED]', $value) ?? $value;

        return $value;
    }
}
