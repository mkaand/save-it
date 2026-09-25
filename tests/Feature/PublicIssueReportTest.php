<?php

namespace Tests\Feature;

use App\Mail\PublicIssueReport;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PublicIssueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_user_can_explicitly_email_a_sanitized_issue_without_creating_analytics(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'ops@example.test']);
        app(ApplicationSettings::class)->put('reports.recipient', $admin->email);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'localhost']);
        Mail::fake();
        $url = 'https://www.facebook.com/video.php?v=123456789012';

        $this->postJson('/api/issue-reports', ['email' => 'visitor@example.test', 'message' => '<b>Broken</b>', 'submitted_url' => $url, 'provider' => 'facebook', 'error_code' => 'provider_response_changed', 'request_id' => 'safe-request-id'])
            ->assertCreated();
        Mail::assertSent(PublicIssueReport::class, function (PublicIssueReport $mail) use ($url): bool {
            $this->assertSame($url, $mail->report['submitted_url']);
            $this->assertSame('visitor@example.test', $mail->report['email']);

            return true;
        });
        $this->assertDatabaseCount('usage_metric_buckets', 0);
    }

    public function test_issue_report_validation_is_public_but_strict(): void
    {
        $this->postJson('/api/issue-reports', ['email' => 'nope', 'message' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'message']);
    }
}
