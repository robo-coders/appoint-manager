<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Booking\AppointmentSuggester;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PreviewBookingController extends Controller
{
    public function __invoke(Request $request, string $token, AppointmentSuggester $suggester): Response
    {
        $tenant = Tenant::query()->where('preview_token', $token)->firstOrFail();

        $request->attributes->set('public_tenant', $tenant);
        app(TenantContext::class)->set($tenant);

        return app(PublicBookingController::class)->show($request, $suggester);
    }
}
