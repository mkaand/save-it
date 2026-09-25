<?php

namespace Tests\Feature;

use App\Mail\RequiredStartTlsTransport;
use App\Models\ApplicationSetting;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\RuntimeConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Tests\TestCase;

final class SmtpSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_transports_and_legacy_values_have_explicit_security_semantics(): void
    {
        $settings = app(ApplicationSettings::class);
        $settings->put('mail.host', 'smtp.example.test');
        foreach ([
            ['starttls', 587, 'smtp', true, true, false],
            ['tls', 465, 'smtp', true, true, false],
            ['smtps', 465, 'smtps', true, true, true],
            ['ssl', 465, 'smtps', true, true, true],
            ['plain', 587, 'smtp', false, false, false],
            ['plain', 465, 'smtp', false, false, false],
            ['none', 587, 'smtp', true, false, false],
            [null, 587, 'smtp', true, false, false],
        ] as [$mode, $port, $scheme, $auto, $required, $implicit]) {
            $settings->put('mail.encryption', $mode);
            $settings->put('mail.port', (string) $port);
            config(['mail.mailers.smtp.url' => 'smtp://wrong.example.test:25']);
            app(RuntimeConfiguration::class)->apply($settings);
            Mail::purge('smtp');
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $this->assertSame($scheme, config('mail.mailers.smtp.scheme'));
            $this->assertNull(config('mail.mailers.smtp.url'));
            $this->assertSame($auto, $transport->isAutoTls());
            $this->assertSame($required, $transport->isTlsRequired());
            $this->assertSame($implicit, $transport->getStream()->isTLS());
            $this->assertSame('smtp.example.test', $transport->getStream()->getHost());
            $this->assertNotFalse($transport->getStream()->getStreamOptions()['ssl']['verify_peer'] ?? true);
            $this->assertNotFalse($transport->getStream()->getStreamOptions()['ssl']['verify_peer_name'] ?? true);
            if (in_array($mode, ['tls', 'starttls'], true)) {
                $this->assertInstanceOf(RequiredStartTlsTransport::class, $transport);
            }
        }
    }

    public function test_starttls_requires_a_successful_handshake_before_authentication(): void
    {
        $stream = new ScriptedSmtpStream(["250-mail\r\n", "250 STARTTLS\r\n", "220 Ready\r\n", "250 mail\r\n"]);
        $transport = new RequiredStartTlsTransport('smtp.example.test', 587, false, stream: $stream);
        $transport->setRequireTls(true);
        $transport->executeCommand("HELO client.example.test\r\n", [250]);
        $this->assertTrue($stream->handshakeAttempted);
        $this->assertCount(3, $stream->commands);
        $this->assertSame("STARTTLS\r\n", $stream->commands[1]);
    }

    public function test_starttls_refuses_missing_extension_helo_fallback_and_failed_handshake(): void
    {
        foreach ([
            [true, ["250-mail\r\n", "250 AUTH PLAIN\r\n"]],
            [true, ["500 EHLO unsupported\r\n", "250 Hello\r\n"]],
            [false, ["250-mail\r\n", "250 STARTTLS\r\n", "220 Ready\r\n"]],
        ] as [$handshake, $replies]) {
            $stream = new ScriptedSmtpStream($replies, $handshake);
            $transport = new RequiredStartTlsTransport('smtp.example.test', 587, false, stream: $stream);
            $transport->setRequireTls(true)->setUsername('private-user')->setPassword('private-password');
            try {
                $transport->executeCommand("HELO client.example.test\r\n", [250]);
                $this->fail('An insecure SMTP connection was accepted.');
            } catch (TransportException) {
                $commands = implode('', $stream->commands);
                $this->assertStringNotContainsString('AUTH', $commands);
                $this->assertStringNotContainsString('MAIL FROM', $commands);
                $this->assertStringNotContainsString('private-password', $commands);
            }
        }
    }

    public function test_editing_legacy_settings_preserves_blank_password_and_hides_secret(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'admin']);
        $settings = app(ApplicationSettings::class);
        $settings->put('mail.host', 'smtp.example.test');
        $settings->put('mail.encryption', 'none');
        $settings->put('mail.password', 'never-render-this', true);
        $ciphertext = ApplicationSetting::findOrFail('mail.password')->value;
        $this->actingAs($admin)->get('/admin/settings/email')->assertOk()
            ->assertSee('Automatic TLS (legacy setting)')->assertDontSee('never-render-this');
        $data = ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'legacy_auto', 'password' => '',
            'username' => 'mailer', 'from_address' => 'save@example.test', 'from_name' => 'Save It'];
        $this->put('/admin/settings/email', $data)->assertSessionHasNoErrors();
        $this->assertSame($ciphertext, ApplicationSetting::findOrFail('mail.password')->value);
        $this->put('/admin/settings/email', [...$data, 'encryption' => 'insecure-guess'])->assertSessionHasErrors('encryption');
        $this->put('/admin/settings/email', [...$data, 'host' => "smtp.example.test\r\ninjected", 'port' => 0,
            'username' => "user\nheader", 'from_address' => 'not-email'])->assertSessionHasErrors(['host', 'port', 'username', 'from_address']);
    }

    public function test_test_email_logs_only_safe_diagnostics_not_exception_credentials(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'admin']);
        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('Authentication failed smtp://private-user:password-value@example.test?token=private-token', 535));
        Log::shouldReceive('warning')->once()->with('mail_delivery_failed', \Mockery::on(function (array $context): bool {
            $this->assertSame(TransportException::class, $context['exception_class']);
            $this->assertSame('authentication_failed', $context['reason']);
            $this->assertSame(535, $context['error_code']);
            foreach (['private-user', 'password-value', 'private-token', 'smtp://'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        }));
        $this->actingAs($admin)->post('/admin/settings/email/test')
            ->assertSessionHasErrors(['email' => 'The test email could not be sent.']);
    }
}

final class ScriptedSmtpStream extends AbstractStream
{
    public array $commands = [];

    public bool $handshakeAttempted = false;

    public function __construct(private array $replies, private bool $handshake = true) {}

    public function initialize(): void {}

    public function setHost(string $host): void {}

    public function setPort(int $port): void {}

    public function disableTls(): void {}

    public function isTLS(): bool
    {
        return false;
    }

    protected function getReadConnectionDescription(): string
    {
        return 'scripted SMTP';
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $this->commands[] = $bytes;
    }

    public function readLine(): string
    {
        return array_shift($this->replies) ?? '';
    }

    public function startTLS(): bool
    {
        $this->handshakeAttempted = true;

        return $this->handshake;
    }
}
