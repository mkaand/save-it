<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueReportRequest;
use App\Mail\IssueReport;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\MailFailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class IssueReportController extends Controller
{
    public function __invoke(IssueReportRequest $request, ApplicationSettings $settings): JsonResponse
    {
        $recipient = $this->recipient($settings);
        if ($recipient === null || ! $this->mailConfigured($settings)) {
            Log::warning('issue_report_unavailable', ['reason' => 'recipient_or_mail_not_configured']);

            return response()->json(['message' => "We couldn't send your report right now. Please try again later."], 503);
        }

        /** @var array<string, string> $report */
        $report = collect($request->validated())
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->all();
        $report['submitted_at'] = now()->toIso8601String();

        try {
            Mail::to($recipient)->send(new IssueReport($report));
        } catch (\Throwable $exception) {
            MailFailureReporter::report('issue_report', $exception);

            return response()->json(['message' => "We couldn't send your report right now. Please try again later."], 503);
        }

        return response()->json(['message' => 'Thanks. Your report has been sent.']);
    }

    private function recipient(ApplicationSettings $settings): ?string
    {
        $configured = $settings->get('reports.recipient');
        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }

        $admin = User::query()->where('is_admin', true)->value('email');

        return is_string($admin) && filter_var($admin, FILTER_VALIDATE_EMAIL) ? $admin : null;
    }

    private function mailConfigured(ApplicationSettings $settings): bool
    {
        if (filled($settings->get('mail.host'))) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'null', 'array'], true)
            && filled(config('mail.mailers.'.config('mail.default').'.host'));
    }
}
