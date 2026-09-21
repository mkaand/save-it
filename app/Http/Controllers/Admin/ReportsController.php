<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\OperationalReportService;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReportsController extends Controller
{
    public function index(Request $request, ApplicationSettings $settings): View
    {
        return view('admin.reports', ['reports' => [
            'mode' => $settings->get('reports.mode', 'disabled'),
            'recipient' => $settings->get('reports.recipient', $request->user()->email),
            'timezone' => $settings->get('reports.timezone', config('app.timezone', 'UTC')),
            'time' => $settings->get('reports.time', '09:00'),
            'weekday' => (int) $settings->get('reports.weekday', 1),
        ]]);
    }

    public function update(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:disabled,daily,weekly'],
            'recipient' => ['nullable', 'email:rfc', 'max:254'],
            'timezone' => ['required', 'timezone'],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['required', 'integer', 'between:1,7'],
        ]);
        $data['recipient'] = $data['recipient'] ?: $request->user()->email;
        foreach ($data as $key => $value) {
            $settings->put('reports.'.$key, (string) ($value ?? ''));
        }

        return back()->with('status', 'Report settings updated.');
    }

    public function send(Request $request, OperationalReportService $reports): RedirectResponse
    {
        return $reports->sendNow($request->user()->email)
            ? back()->with('status', 'Operational report sent.')
            : back()->withErrors(['reports' => 'Reports require a working email configuration.']);
    }
}
