<?php

namespace Tests\Feature;

use App\Mail\IssueReport;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class IssueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_user_can_send_an_escaped_report_to_the_configured_recipient_without_persistence(): void
    {
        Mail::fake();
        $this->settings()->put('mail.host', 'smtp.example.test');
        $this->settings()->put('reports.recipient', 'support@example.test');

        $this->postJson('/report-issue', [
            'email' => 'person@example.test', 'message' => '<strong>Broken</strong>',
            'submitted_url' => 'https://example.test/watch?v=one', 'provider' => 'youtube',
            'error_code' => 'upstream_unavailable', 'request_id' => 'request_123',
            'diagnostics' => 'token=secret-value',
        ])->assertOk()->assertJsonPath('message', 'Thanks. Your report has been sent.');

        Mail::assertSent(IssueReport::class, function (IssueReport $mail): bool {
            $mail->assertTo('support@example.test');
            $mail->assertHasReplyTo('person@example.test');
            $mail->assertSeeInHtml('&lt;strong&gt;Broken&lt;/strong&gt;', false);
            $mail->assertDontSeeInHtml('<strong>Broken</strong>', false);
            $mail->assertSeeInHtml('https://example.test/watch?v=one', false);
            $mail->assertSeeInHtml('upstream_unavailable', false);
            $mail->assertDontSeeInHtml('secret-value', false);

            return true;
        });
        $this->assertDatabaseCount('usage_metric_buckets', 0);
        $this->assertDatabaseCount('application_settings', 2);
    }

    public function test_admin_email_is_the_safe_recipient_fallback(): void
    {
        Mail::fake();
        $this->settings()->put('mail.host', 'smtp.example.test');
        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.test']);

        $this->postJson('/report-issue', ['email' => 'person@example.test', 'message' => 'A report'])->assertOk();

        Mail::assertSent(IssueReport::class, function (IssueReport $mail): bool {
            $mail->assertTo('admin@example.test');
            $mail->assertDontSeeInHtml('Request context', false);

            return true;
        });
    }

    public function test_report_validation_rejects_bad_or_missing_user_content_and_does_not_send_mail(): void
    {
        Mail::fake();
        $this->settings()->put('mail.host', 'smtp.example.test');
        $this->settings()->put('reports.recipient', 'support@example.test');

        $this->postJson('/report-issue', ['email' => "bad@example.test\r\nBcc: victim@example.test", 'message' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'message']);
        $this->postJson('/report-issue', ['email' => 'person@example.test', 'message' => str_repeat('x', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        Mail::assertNothingSent();
    }

    public function test_missing_recipient_or_mail_configuration_returns_a_safe_error_without_sending(): void
    {
        Mail::fake();
        Log::spy();

        $this->postJson('/report-issue', ['email' => 'person@example.test', 'message' => 'A report'])
            ->assertStatus(503)->assertJsonPath('message', "We couldn't send your report right now. Please try again later.");
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('issue_report_unavailable', ['reason' => 'recipient_or_mail_not_configured'])->once();
    }

    public function test_mail_transport_failure_is_generic_and_logs_no_report_content(): void
    {
        $this->settings()->put('mail.host', 'smtp.example.test');
        $this->settings()->put('reports.recipient', 'support@example.test');
        Log::spy();
        Mail::shouldReceive('to')->once()->with('support@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('secret-url@example.test token=private-token'));

        $this->postJson('/report-issue', ['email' => 'person@example.test', 'message' => 'private message', 'submitted_url' => 'https://private.example/token'])
            ->assertStatus(503)->assertJsonPath('message', "We couldn't send your report right now. Please try again later.");

        Log::shouldHaveReceived('warning')->with('mail_delivery_failed', \Mockery::on(function (array $context): bool {
            return $context['operation'] === 'issue_report'
                && ! str_contains(implode(' ', $context), 'private-token')
                && ! str_contains(implode(' ', $context), 'private message');
        }))->once();
    }

    public function test_only_allowlisted_diagnostic_context_is_accepted_and_reports_are_rate_limited(): void
    {
        Mail::fake();
        $this->settings()->put('mail.host', 'smtp.example.test');
        $this->settings()->put('reports.recipient', 'support@example.test');
        $payload = ['email' => 'person@example.test', 'message' => 'A report', 'provider' => 'youtube', 'error_code' => 'invalid_url'];

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.17'])->postJson('/report-issue', [...$payload, 'provider' => 'untrusted-provider'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.18']);
        for ($attempt = 0; $attempt < 5; $attempt += 1) {
            $this->postJson('/report-issue', $payload)->assertOk();
        }
        $this->postJson('/report-issue', $payload)->assertStatus(429);
        Mail::assertSentCount(5);
    }

    private function settings(): ApplicationSettings
    {
        return app(ApplicationSettings::class);
    }
}
