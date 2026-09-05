<?php

/*
|--------------------------------------------------------------------------
| Beta sandbox — app.{domain}/settings/beta-sandbox
|--------------------------------------------------------------------------
|
| Required from routes/app.php, inside the `auth` + `tenant` + `onboarding` +
| `subscribed` group, so these four routes inherit exactly the protection every
| other operator screen has and nothing about them is special-cased.
|
| A tenant that is not `is_beta` gets 404 from the controller, not from a
| missing route: the routes exist for everybody and answer for nobody else. See
| App\Http\Controllers\BetaSandbox\SandboxController and BETA_SANDBOX.md.
|
| Deleting the beta sandbox means deleting this file and the one `require` in
| routes/app.php that reaches it.
|
*/

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
