<?php

use App\Sandbox\FlakyNetwork;
use App\Sandbox\ToolkitController;
use Illuminate\Support\Facades\Route;

FlakyNetwork::register();

Route::post('/settings/beta-sandbox/jump', [ToolkitController::class, 'jump'])
    ->name('beta-sandbox.jump');

Route::post('/settings/beta-sandbox/no-show', [ToolkitController::class, 'noShow'])
    ->name('beta-sandbox.no-show');

Route::post('/settings/beta-sandbox/waitlist-free', [ToolkitController::class, 'waitlistFree'])
    ->name('beta-sandbox.waitlist-free');

Route::post('/settings/beta-sandbox/waitlist-expire', [ToolkitController::class, 'waitlistExpire'])
    ->name('beta-sandbox.waitlist-expire');

Route::post('/settings/beta-sandbox/remind', [ToolkitController::class, 'remind'])
    ->name('beta-sandbox.remind');

Route::post('/settings/beta-sandbox/outbox-clear', [ToolkitController::class, 'clearOutbox'])
    ->name('beta-sandbox.outbox-clear');

Route::post('/settings/beta-sandbox/flaky', [ToolkitController::class, 'flaky'])
    ->name('beta-sandbox.flaky');
