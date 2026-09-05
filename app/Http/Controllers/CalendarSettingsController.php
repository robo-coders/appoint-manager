<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar sync, owner-facing and only owner-facing.
 *
 * There is no staff login, so there is nobody to show a "your calendar" screen
 * to. The owner copies each person's link and sends it to them however she
 * already talks to them — WhatsApp, a text, on paper. That is the whole
 * distribution mechanism and it is deliberate: the alternative is inviting five
 * people to create accounts in order to read a list of times.
 *
 * **Nothing here is customer-facing, and there is no route that makes it so.**
 * A customer never sees a staff calendar, never sees a token, and the public
 * booking surface has no link to any of this.
 *
 * The token is minted on first view rather than at staff creation, so a salon
 * that never opens this screen has no live tokens at all — see
 * `User::calendarToken()`.
 */
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
                /*
                 * The absolute URL on the app host, built from `Surface::App`
                 * rather than `route()`, because the owner is about to paste it
                 * into somebody else's phone. A relative path, or a URL on
                 * whatever host she happens to be browsing, is a link that works
                 * for her and not for them.
                 */
                'url' => Surface::App->to('calendar/'.$user->calendarToken().'.ics'),
            ]);

        return Inertia::render('Settings/Calendar', ['staff' => $staff]);
    }

    /**
     * Issue a new link and retire the old one.
     *
     * The only recovery from a link that reached somebody it should not have.
     * It takes effect on the next poll, and the screen says the member of staff
     * has to be sent the new link — their calendar app will keep asking for the
     * old address and quietly show an empty calendar, which is a confusing thing
     * to leave unexplained.
     */
    public function regenerate(User $staff): RedirectResponse
    {
        $this->authorize('update', $staff);

        $staff->regenerateCalendarToken();

        return redirect()
            ->route('settings.calendar.show')
            ->with('toast', 'New link for '.$staff->name.'. Send it to them — the old one has stopped working.');
    }
}
