<?php

use App\Http\Controllers\AnalyzeController;
use Illuminate\Support\Facades\Route;

Route::post('/analyze', AnalyzeController::class)
    ->middleware('throttle:analyze')
    ->name('api.analyze');
