<?php

/*
|--------------------------------------------------------------------------
| Marketing — the apex domain
|--------------------------------------------------------------------------
|
| No auth, no tenant context, no session writes. Publicly cacheable, which is
| why nothing here may vary by who is logged in.
|
*/

use App\Http\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

Route::middleware('cache.headers:public;max_age=300;etag')->group(function (): void {
    Route::get('/', [MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/how-it-works', [MarketingController::class, 'howItWorks'])->name('marketing.how-it-works');
    Route::get('/dog-grooming', [MarketingController::class, 'dogGrooming'])->name('marketing.dog-grooming');
    Route::get('/about', [MarketingController::class, 'about'])->name('marketing.about');
    Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
    Route::get('/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy');
    Route::get('/terms', [MarketingController::class, 'terms'])->name('marketing.terms');
    Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('marketing.sitemap');
    Route::get('/robots.txt', [MarketingController::class, 'robots'])->name('marketing.robots');
});
