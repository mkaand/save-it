<?php

namespace App\Services\Reports;

use App\Models\AdminReportDelivery;
use App\Models\User;
use App\Services\Analytics\UsageMetrics;
use App\Services\Operations\OperationsService;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Support\Facades\Mail;

final class OperationalReportService
{
    public function sendNow(?string $recipient = null): bool
    {
        $recipient ??= User::query()->where('is_admin', true)->value('email');
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! $this->mailConfigured()) {
            return false;
        }

        $summary = app(UsageMetrics::class)->summary(24);
        $snapshot = app(OperationsService::class)->snapshot();
        $text = $this->message($summary, $snapshot);

        try {
            Mail::raw($text, fn ($mail) => $mail->to($recipient)->subject('Save It operational report'));

            return true;
        } catch (\Throwable) {
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
        if ($this->sendNow($recipient !== '' ? $recipient : null)) {
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

    /** @param array<string,mixed> $summary @param array<string,mixed> $snapshot */
    private function message(array $summary, array $snapshot): string
    {
        $rows = $summary['rows'];
        $providers = $rows->groupBy('provider')->map(fn ($items, $key) => $key.': '.$items->sum('count'))->implode("\n");
        $countries = $rows->groupBy('country_code')->sortByDesc(fn ($items) => $items->sum('count'))->take(5)->map(fn ($items, $key) => $key.': '.$items->sum('count'))->implode("\n");
        $errors = $rows->where('successful', false)->groupBy('error_code')->map(fn ($items, $key) => $key.': '.$items->sum('count'))->implode("\n");
        $storage = collect($snapshot['storage'])->map(fn ($item, $key) => $key.': '.$item['count'].' files, '.$item['size'].' bytes')->implode("\n");

        return "Save It operational report (last 24 hours)\n\nAnalyses and operations: {$summary['total']}\nSuccesses: {$summary['success']}\nErrors: {$summary['failed']}\n\nProviders:\n{$providers}\n\nCountries:\n{$countries}\n\nErrors:\n{$errors}\n\nQueue depth: {$snapshot['queue']['queue_depth']}\nFailed jobs: {$snapshot['queue']['failed_jobs']}\n\nStorage:\n{$storage}";
    }
}
