<?php

use App\Services\Downloads\ShareMediaPreparationStore;
use App\Services\Runtime\RuntimeArtifactCleanup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(RuntimeArtifactCleanup::class)->downloads())
    ->everyThirtyMinutes()
    ->name('download-artifact-cleanup')
    ->withoutOverlapping();

Schedule::call(fn () => app(ShareMediaPreparationStore::class)->cleanupExpired())
    ->everyThirtyMinutes()
    ->name('share-preparation-cleanup')
    ->withoutOverlapping();
