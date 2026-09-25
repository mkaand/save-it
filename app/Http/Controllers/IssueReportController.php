<?php

namespace App\Http\Controllers;

use App\Mail\PublicIssueReport;
use App\Models\User;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\MailFailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

final class IssueReportController extends Controller
{
    public function store(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'max:4000'],
            'submitted_url' => ['nullable', 'string', 'max:2048'],
            'provider' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,32}$/'],
            'error_code' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,80}$/'],
            'request_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/'],
        ]);
        $recipient = $settings->get('reports.recipient') ?: User::query()->where('is_admin', true)->value('email');
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! $this->mailConfigured($settings)) {
            return response()->json(['error' => ['message' => 'Issue reports are temporarily unavailable. Please try again later.']], 503);
        }
        try {
            Mail::to($recipient)->send(new PublicIssueReport($data));

            return response()->json(['data' => ['message' => 'Thank you. Your report was sent.']], 201);
        } catch (\Throwable $exception) {
            MailFailureReporter::report('public_issue_report', $exception);

            return response()->json(['error' => ['message' => 'Your report could not be sent. Please try again later.']], 503);
        }
    }

    private function mailConfigured(ApplicationSettings $settings): bool
    {
        return filled($settings->get('mail.host')) || (! in_array(config('mail.default'), ['log', 'null', 'array'], true) && filled(config('mail.mailers.'.config('mail.default').'.host')));
    }
}
