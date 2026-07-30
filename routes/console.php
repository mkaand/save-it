<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $root = storage_path('app/private/downloads');
    if (! is_dir($root)) {
        return;
    }
    foreach (File::directories($root) as $directory) {
        if (filemtime($directory) !== false && filemtime($directory) < now()->subHours(2)->timestamp) {
            File::deleteDirectory($directory);
        }
    }
})->everyThirtyMinutes()->name('download-artifact-cleanup')->withoutOverlapping();
