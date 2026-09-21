<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationsService;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class OperationsController extends Controller
{
    public function index(OperationsService $operations, ApplicationSettings $settings): View
    {
        return view('admin.operations', [
            'snapshot' => $operations->snapshot(),
            'lastCleanup' => $settings->get('operations.cleanup.last_at'),
            'lastCleanupStatus' => $settings->get('operations.cleanup.last_status', 'Not run'),
        ]);
    }

    public function cleanup(OperationsService $operations): RedirectResponse
    {
        try {
            $operations->cleanup();

            return back()->with('status', 'Application-managed expired artifacts were cleaned.');
        } catch (\Throwable) {
            return back()->withErrors(['operations' => 'Cleanup could not be completed safely.']);
        }
    }
}
