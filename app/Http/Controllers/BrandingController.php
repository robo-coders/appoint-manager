<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Support\BrandPalette;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings -> Branding. One choice, six options, and a preview of the only
 * page it changes.
 */
class BrandingController extends Controller
{
    public function edit(): Response
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        return Inertia::render('Settings/Branding', [
            /*
             * Names only. The screen renders each swatch as `var(--brand-navy)`
             * and never sees a hex, so the colours cannot be one thing in the
             * picker and another on the booking page.
             */
            'presets' => BrandPalette::names(),
            'current' => $tenant->brand_colour,
            // The preview needs to look like this salon's page, not a stub.
            'businessName' => $tenant->name,
        ]);
    }

    public function update(UpdateBrandingRequest $request): RedirectResponse
    {
        current_tenant()?->update($request->validated());

        return redirect()->route('settings.branding.edit')->with('toast', 'Branding saved.');
    }
}
