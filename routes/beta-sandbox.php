<?php

use App\Http\Controllers\BetaSandbox\SandboxController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/beta-sandbox', [SandboxController::class, 'show'])
    ->name('beta-sandbox.show');

Route::post('/settings/beta-sandbox/sample-data', [SandboxController::class, 'sampleData'])
    ->name('beta-sandbox.sample-data');

Route::post('/settings/beta-sandbox/fast-forward', [SandboxController::class, 'fastForward'])
    ->name('beta-sandbox.fast-forward');

Route::post('/settings/beta-sandbox/reset', [SandboxController::class, 'reset'])
    ->name('beta-sandbox.reset');
