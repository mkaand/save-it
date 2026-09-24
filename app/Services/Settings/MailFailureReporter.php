<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Log;
use Throwable;

final class MailFailureReporter
{
    public static function report(string $operation, Throwable $exception): void
    {
        // SMTP replies may echo AUTH payloads, usernames, DSNs or message bodies.
        // Classify internally; emit only a fixed diagnostic message, never the
        // exception object, stack trace, raw reply or transport debug transcript.
        $message = strtolower($exception->getMessage());
        [$reason, $safeMessage] = match (true) {
            str_contains($message, 'scheme') => ['invalid_transport', 'The SMTP transport scheme is invalid.'],
            str_contains($message, 'certificate'), str_contains($message, 'tls'), str_contains($message, 'ssl') => ['tls_failure', 'The SMTP TLS negotiation or certificate verification failed.'],
            str_contains($message, 'authenticat'), str_contains($message, '535') => ['authentication_failed', 'The SMTP server rejected authentication.'],
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => ['timeout', 'The SMTP operation timed out.'],
            str_contains($message, 'connect'), str_contains($message, 'getaddrinfo') => ['connection_failed', 'The SMTP connection could not be established.'],
            default => ['send_failed', 'The configured mail transport could not send the message.'],
        };
        Log::warning('mail_delivery_failed', [
            'operation' => $operation,
            'exception_class' => $exception::class,
            'reason' => $reason,
            'safe_message' => $safeMessage,
            'error_code' => is_int($exception->getCode()) ? $exception->getCode() : 0,
        ]);
    }
}
