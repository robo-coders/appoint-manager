<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        return Inertia::render('Settings/Index', [
            'business' => [
                'name' => $tenant->name,
                'timezone' => $tenant->timezone,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address_line_1' => $tenant->address_line_1,
                'address_line_2' => $tenant->address_line_2,
                'city' => $tenant->city,
                'postcode' => $tenant->postcode,
            ],
            'timezones' => Timezones::identifiers(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        current_tenant()?->update($request->validated());

        return redirect()->route('settings.edit')->with('toast', 'Changes saved.');
    }
}
