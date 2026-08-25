<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PreviewBookingController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        $tenant = Tenant::query()->where('preview_token', $token)->firstOrFail();

        $request->attributes->set('public_tenant', $tenant);
        app(\App\Support\TenantContext::class)->set($tenant);

        return app(PublicBookingController::class)->show($request);
    }
}
