<?php

namespace App\Services\Reports;

use App\Mail\OperationalReport;
use App\Models\AdminReportDelivery;
use App\Models\User;
use App\Services\Analytics\UsageMetrics;
use App\Services\Operations\OperationsService;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\MailFailureReporter;
use Illuminate\Support\Facades\Mail;

final class OperationalReportService
{
    public function sendNow(?string $recipient = null, ?int $hours = null): bool
    {
        $recipient ??= User::query()->where('is_admin', true)->value('email');
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! $this->mailConfigured()) {
            return false;
        }

        $settings = app(ApplicationSettings::class);
        $hours ??= $settings->get('reports.mode') === 'weekly' ? 168 : 24;
        $hours = $hours === 168 ? 168 : 24;
        $timezone = (string) $settings->get('reports.timezone', config('app.timezone', 'UTC'));
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }
        $summary = app(UsageMetrics::class)->summary($hours);
        $snapshot = app(OperationsService::class)->snapshot();
        $report = app(OperationalReportData::class)->build($summary, $snapshot, $hours, $timezone);

        try {
            Mail::to($recipient)->send(new OperationalReport($report));

            return true;
        } catch (\Throwable $exception) {
            MailFailureReporter::report('operational_report', $exception);

            return false;
        }
    }

    public function sendDue(): void
    {
        $settings = app(ApplicationSettings::class);
        $mode = (string) $settings->get('reports.mode', 'disabled');
        if (! in_array($mode, ['daily', 'weekly'], true)) {
            return;
        }
        $timezone = (string) $settings->get('reports.timezone', config('app.timezone', 'UTC'));
        try {
            $now = now($timezone);
        } catch (\Throwable) {
            return;
        }
        $time = (string) $settings->get('reports.time', '09:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $now->format('H:i') !== $time) {
            return;
        }
        if ($mode === 'weekly' && (int) $settings->get('reports.weekday', 1) !== $now->dayOfWeekIso) {
            return;
        }
        $period = $mode.'-'.$now->toDateString();
        if (AdminReportDelivery::query()->where('period_key', $period)->exists()) {
            return;
        }
        $recipient = (string) $settings->get('reports.recipient', '');
        if ($this->sendNow($recipient !== '' ? $recipient : null, $mode === 'weekly' ? 168 : 24)) {
            AdminReportDelivery::query()->create(['period_key' => $period, 'sent_at' => now()]);
        }
    }

    private function mailConfigured(): bool
    {
        $settings = app(ApplicationSettings::class);
        if (filled($settings->get('mail.host'))) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'null', 'array'], true)
            && filled(config('mail.mailers.'.config('mail.default').'.host'));
    }
}
