<?php

use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\Downloads\DownloadController;
use App\Http\Controllers\Downloads\DownloadJobController;
use App\Http\Controllers\Previews\RecentPreviewController;
use Illuminate\Support\Facades\Route;

Route::post('/analyze', AnalyzeController::class)
    ->middleware('throttle:analyze')
    ->name('api.analyze');

Route::get('/downloads/{token}', DownloadController::class)
    ->where('token', '[a-z0-9]{48}\.[a-f0-9]{64}')
    ->middleware('throttle:downloads')
    ->name('api.downloads.show');

Route::get('/previews/{preview}', RecentPreviewController::class)
    ->where('preview', '[a-z0-9]{48}')
    ->middleware('throttle:downloads')
    ->name('api.previews.show');

Route::post('/download-jobs', [DownloadJobController::class, 'store'])
    ->middleware('throttle:downloads')
    ->name('api.download-jobs.store');

Route::get('/download-jobs/{job}', [DownloadJobController::class, 'show'])
    ->whereUuid('job')
    ->middleware('throttle:downloads')
    ->name('api.download-jobs.show');
