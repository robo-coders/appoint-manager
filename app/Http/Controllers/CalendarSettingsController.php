<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CalendarSettingsController extends Controller
{
    public function show(): Response
    {
        $this->authorize('viewAny', User::class);

        $tenant = current_tenant();

        abort_unless($tenant, 403);

        $staff = User::query()
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => $user->is_active,
                'url' => Surface::App->to('calendar/'.$user->calendarToken().'.ics'),
            ]);

        return Inertia::render('Settings/Calendar', ['staff' => $staff]);
    }

    public function regenerate(User $staff): RedirectResponse
    {
        $this->authorize('update', $staff);

        $staff->regenerateCalendarToken();

        return redirect()
            ->route('settings.calendar.show')
            ->with('toast', 'New link for '.$staff->name.'. Send it to them — the old one has stopped working.');
    }
}
