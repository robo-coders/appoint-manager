<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Support\BookingQr;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SettingsController extends Controller
{
    public function edit(): InertiaResponse
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        $url = $tenant->publicBookingUrl();
        $live = $tenant->booking_page_live;

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
                'booking_mode' => $tenant->booking_mode->value,
                'request_requires_deposit' => $tenant->request_requires_deposit,
            ],
            'booking_link' => [
                'url' => $url,
                'live' => $live,
                'qr_url' => $live ? route('settings.booking-link.qr') : null,
                'qr_download_url' => $live ? route('settings.booking-link.qr', ['download' => 1]) : null,
            ],
            'timezones' => Timezones::identifiers(),
        ]);
    }

    public function qr(Request $request): Response
    {
        $tenant = current_tenant();

        if ($tenant === null || ! $tenant->booking_page_live) {
            abort(404);
        }

        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$tenant->slug.'.png"';
        }

        return response(BookingQr::png($tenant->publicBookingUrl()), 200, $headers);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        current_tenant()?->update($request->validated());

        return redirect()->route('settings.edit')->with('toast', 'Changes saved.');
    }
}
