<?php

namespace Tests\Feature;

use App\Mail\OperationalReport;
use App\Models\UsageMetricBucket;
use App\Services\Analytics\UsageMetrics;
use App\Services\Reports\OperationalReportData;
use App\Services\Reports\OperationalReportService;
use App\Services\Settings\ApplicationSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class OperationalReportEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_aggregate_semantics_and_renders_html_and_text(): void
    {
        $this->freezeTime();
        $this->metric('youtube', 'analyze', true, 'TR', 6);
        $this->metric('facebook', 'download', true, 'DE', 2);
        $this->metric('unknown', 'analyze', false, 'ZZ', 1, 'invalid_url');
        $this->metric('tiktok', 'share_preparation', true, 'US', 1);
        $this->metric('unknown', 'rate_limit_hit', false, 'ZZ', 1, 'rate_limited');
        $summary = app(UsageMetrics::class)->summary();
        // Even unexpected future model attributes must not reach the email.
        $summary['rows']->first()->setAttribute('submitted_url', 'https://private.example/media?token=do-not-render');
        $summary['rows']->first()->setAttribute('raw_ip', '203.0.113.99');
        $data = app(OperationalReportData::class)->build($summary, $this->snapshot(), 24, 'UTC');
        $this->assertSame(11, $data['total']);
        $this->assertSame(7, $data['analyses']);
        $this->assertSame(2, $data['downloads']);
        $this->assertSame(1, $data['share_preparations']);
        $this->assertSame(1, $data['rate_limits']);
        $this->assertSame('81.8%', $data['success_rate']);
        $mail = new OperationalReport($data);
        $mail->assertSeeInHtml('Executive summary');
        foreach (['YouTube', 'Facebook', 'Unknown / unclassified', 'ZZ · Unknown', 'TR', 'invalid_url', 'rate_limited', '1.0 MiB', 'Activity', 'System &amp; operations'] as $text) {
            $mail->assertSeeInHtml($text, false);
        }
        $mail->assertSeeInText('Daily · last 24 hours');
        foreach (['private.example', 'do-not-render', '203.0.113.99', '<script', '<svg', '<canvas', '<img'] as $text) {
            $this->assertStringNotContainsString($text, $mail->render());
        }
        $this->assertCount(1, $data['activity']);
        $this->assertSame(11, $data['activity'][0]['count']);
    }

    public function test_empty_report_warning_and_escaping(): void
    {
        $data = app(OperationalReportData::class)->build(app(UsageMetrics::class)->summary(), $this->snapshot(), 24, 'UTC');
        $this->assertSame('No recorded activity', $data['status']);
        $this->assertSame('—', $data['success_rate']);
        $this->assertFalse($data['warning']);
        (new OperationalReport($data))->assertSeeInHtml('No errors recorded in this period.');
        $snapshot = $this->snapshot();
        $snapshot['queue']['failed_jobs'] = 1;
        $warning = app(OperationalReportData::class)->build(app(UsageMetrics::class)->summary(), $snapshot, 168, 'UTC');
        $this->assertSame('Review needed', $warning['status']);
        $warning['providers'][] = ['label' => '<script>alert("secret")</script>', 'count' => 1, 'percent' => 100];
        $html = (new OperationalReport($warning))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Weekly · last 7 days', $html);
        $this->assertStringContainsString('Review needed', $html);
        foreach ([0 => '0 B', 512 => '512 B', 1024 => '1.0 KiB', 1048576 => '1.0 MiB', 1073741824 => '1.0 GiB'] as $bytes => $expected) {
            $this->assertSame($expected, OperationalReportData::bytes($bytes));
        }
    }

    public function test_scheduled_weekly_report_uses_seven_days_and_deduplicates(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 09:00:00', 'UTC'));
        $settings = app(ApplicationSettings::class);
        foreach (['mode' => 'weekly', 'recipient' => 'admin@example.test', 'timezone' => 'UTC', 'time' => '09:00', 'weekday' => '1'] as $key => $value) {
            $settings->put('reports.'.$key, $value);
        }
        $settings->put('mail.host', 'smtp.example.test');
        $this->metric('x', 'analyze', true, 'ZZ', 5, '', 72);
        $this->metric('x', 'analyze', true, 'ZZ', 9, '', 200);
        Mail::fake();
        $service = app(OperationalReportService::class);
        $service->sendDue();
        $service->sendDue();
        Mail::assertSent(OperationalReport::class, fn ($mail) => $mail->report['total'] === 5 && $mail->report['period'] === 'Weekly · last 7 days' && $mail->hasTo('admin@example.test'));
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('admin_report_deliveries', 1);
        $this->assertTrue($service->sendNow('admin@example.test', 24));
        Mail::assertSent(OperationalReport::class, fn ($mail) => $mail->report['total'] === 0 && $mail->report['period'] === 'Daily · last 24 hours');
        $settings->put('reports.mode', 'disabled');
        $service->sendDue();
        Mail::assertSentCount(2);
    }

    public function test_mail_contains_both_html_and_plain_text_alternatives(): void
    {
        $data = app(OperationalReportData::class)->build(app(UsageMetrics::class)->summary(), $this->snapshot(), 24, 'UTC');
        config(['mail.default' => 'array']);
        $mail = (new OperationalReport($data))->to('admin@example.test');
        $mail->withSymfonyMessage(function ($message): void {
            $this->assertStringContainsString('Executive summary', $message->getHtmlBody());
            $this->assertStringContainsString('Queue depth', $message->getTextBody());
            $this->assertStringContainsString('multipart/alternative', $message->toString());
        });
        Mail::send($mail);
    }

    private function metric(string $provider, string $operation, bool $successful, string $country, int $count, string $error = '', int $hoursAgo = 1): void
    {
        UsageMetricBucket::query()->create(['bucket_start' => now()->subHours($hoursAgo)->startOfHour(), 'provider' => $provider,
            'operation' => $operation, 'successful' => $successful, 'country_code' => $country, 'count' => $count, 'error_code' => $error]);
    }

    private function snapshot(): array
    {
        return ['queue' => ['queue_depth' => 0, 'failed_jobs' => 0], 'storage' => [
            'share_preparations' => ['count' => 1, 'size' => 1048576],
            'downloads' => ['count' => 0, 'size' => 0], 'recent_previews' => ['count' => 2, 'size' => 2048],
        ]];
    }
}
