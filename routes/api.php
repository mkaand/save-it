<?php

use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\Downloads\DownloadController;
use App\Http\Controllers\Downloads\DownloadJobController;
use App\Http\Controllers\Downloads\ShareMediaPreparationController;
use App\Http\Controllers\Downloads\ShareMediaPreparationDownloadController;
use App\Http\Controllers\Previews\RecentPreviewController;
use Illuminate\Support\Facades\Route;

Route::post('/analyze', AnalyzeController::class)
    ->middleware('redis.rate:analyze')
    ->name('api.analyze');

Route::get('/downloads/{token}', DownloadController::class)
    ->where('token', '[a-z0-9]{48}\.[a-f0-9]{64}')
    ->middleware(['redis.rate:download', 'token.format'])
    ->name('api.downloads.show');

Route::post('/share-preparations', ShareMediaPreparationController::class)
    ->middleware(['redis.rate:share', 'token.format'])
    ->name('api.share-preparations.store');

Route::get('/share-preparations/{preparation}', ShareMediaPreparationDownloadController::class)
    ->where('preparation', '[a-z0-9]{48}')
    ->middleware('redis.rate:download')
    ->name('api.share-preparations.show');

Route::get('/previews/{preview}', RecentPreviewController::class)
    ->where('preview', '[a-z0-9]{48}')
    ->middleware('redis.rate:preview')
    ->name('api.previews.show');

Route::post('/download-jobs', [DownloadJobController::class, 'store'])
    ->middleware(['redis.rate:job_create', 'token.format'])
    ->name('api.download-jobs.store');

Route::get('/download-jobs/{job}', [DownloadJobController::class, 'show'])
    ->whereUuid('job')
    ->middleware('redis.rate:job_poll')
    ->name('api.download-jobs.show');
