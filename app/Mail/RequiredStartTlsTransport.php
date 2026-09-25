<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class RequiredStartTlsTransport extends EsmtpTransport
{
    private bool $startTlsAccepted = false;

    public function executeCommand(string $command, array $codes): string
    {
        $hello = str_starts_with($command, 'HELO ');
        if ($hello) {
            $this->startTlsAccepted = false;
        }
        $response = parent::executeCommand($command, $codes);
        if ($command === "STARTTLS\r\n") {
            $this->startTlsAccepted = true;
        }
        // Symfony 7.4's EHLO -> HELO fallback returns before require_tls is
        // checked. Refuse that plaintext fallback before any mail is submitted.
        // A failed TLS handshake throws inside parent before HELO can return.
        if ($hello && ! $this->startTlsAccepted) {
            throw new TransportException('STARTTLS is required for this SMTP connection.');
        }

        return $response;
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        // Explicit false prevents port 465 implicitly enabling SMTPS instead.
        $transport = new self($config['host'], (int) $config['port'], false);
        $transport->setAutoTls(true)->setRequireTls(true);
        $transport->setUsername((string) ($config['username'] ?? ''));
        $transport->setPassword((string) ($config['password'] ?? ''));
        if (filled($config['local_domain'] ?? null)) {
            $transport->setLocalDomain($config['local_domain']);
        }
        $transport->getStream()->setTimeout((float) ($config['timeout'] ?? 30));

        return $transport;
    }
}
