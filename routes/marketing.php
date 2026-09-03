<?php

/*
|--------------------------------------------------------------------------
| Marketing — the apex domain
|--------------------------------------------------------------------------
|
| No auth, no tenant context, no session writes. Publicly cacheable, which is
| why nothing in the cached group may vary by who is logged in.
|
| The contact form is the exception and is outside that group. It carries a
| CSRF token, and a shared cache that hands one visitor's token to another is a
| 419 on submit for everybody but the first person through.
|
*/

use App\Http\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

Route::middleware('cache.headers:public;max_age=300;etag')->group(function (): void {
    Route::get('/', [MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/how-it-works', [MarketingController::class, 'howItWorks'])->name('marketing.how-it-works');

    /*
     * The trade pages. One per vertical we have written copy for, each a copy
     * of `marketing/dog-grooming.blade.php` rendering the shared
     * `marketing/partials/vertical-page.blade.php` template. A new one is a
     * line here, a method on the controller, a copy file, and an entry in
     * `App\Support\MarketingNav` so the footer links it.
     */
    Route::get('/dog-grooming', [MarketingController::class, 'dogGrooming'])->name('marketing.dog-grooming');

    Route::get('/about', [MarketingController::class, 'about'])->name('marketing.about');
    Route::get('/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy');
    Route::get('/terms', [MarketingController::class, 'terms'])->name('marketing.terms');
    Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('marketing.sitemap');
    Route::get('/robots.txt', [MarketingController::class, 'robots'])->name('marketing.robots');
});

Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
Route::post('/contact', [MarketingController::class, 'sendContact'])->name('marketing.contact.send');
