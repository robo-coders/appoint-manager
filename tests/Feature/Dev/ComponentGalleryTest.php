<?php

use Illuminate\Support\Facades\Route;

it('serves the gallery outside production', function () {
    $this->get('/dev/components')->assertOk();
});

it('does not require a login', function () {
    $this->assertGuest();
    $this->get('/dev/components')->assertOk();
});

it('is registered behind an environment check so production never has the route', function () {
    $source = (string) file_get_contents(app_path('Support/SurfaceRoutes.php'));

    expect($source)->toContain("if (app()->environment('production')) {")
        ->and($source)->toContain('return;')
        ->and(Route::has('dev.components'))->toBeTrue();
});
