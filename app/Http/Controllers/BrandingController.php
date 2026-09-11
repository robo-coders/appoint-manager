<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Support\BrandPalette;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(): Response
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        return Inertia::render('Settings/Branding', [
            'presets' => BrandPalette::names(),
            'current' => $tenant->brand_colour,
            'businessName' => $tenant->name,
        ]);
    }

    public function update(UpdateBrandingRequest $request): RedirectResponse
    {
        current_tenant()?->update($request->validated());

        return redirect()->route('settings.branding.edit')->with('toast', 'Branding saved.');
    }
}
