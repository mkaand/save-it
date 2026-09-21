<?php

use App\Services\Operations\OperationsService;
use App\Services\Reports\OperationalReportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(OperationsService::class)->cleanup())
    ->everyThirtyMinutes()
    ->name('runtime-artifact-cleanup')
    ->withoutOverlapping();

Schedule::call(fn () => app(OperationalReportService::class)->sendDue())
    ->everyMinute()
    ->name('operational-report-delivery')
    ->withoutOverlapping();
