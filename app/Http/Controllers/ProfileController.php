<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException as ValidationError;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Close the user's account.
     *
     * Bookings reference this user as their staff member, so the row cannot simply
     * be deleted: past appointments would lose their staff name and the database
     * would refuse the delete anyway. Instead the person is erased — name, email,
     * credentials — and the row is retired so the diary's history stays readable.
     */
    public function destroy(Request $request): HttpResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $upcoming = Booking::withoutGlobalScopes()
            ->where('staff_id', $user->id)
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
            ->where('starts_at', '>=', now())
            ->count();

        if ($upcoming > 0) {
            throw ValidationError::withMessages([
                'password' => trans_choice(
                    'You still have :count upcoming appointment. Cancel or reassign it before closing your account.'
                    .'|You still have :count upcoming appointments. Cancel or reassign them before closing your account.',
                    $upcoming,
                    ['count' => $upcoming],
                ),
            ]);
        }

        if ($user->isOwner() && $this->isLastOwner($user)) {
            throw ValidationError::withMessages([
                'password' => 'You are the last owner of this business. Invite another owner before closing your account.',
            ]);
        }

        $this->anonymise($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        /*
         * Same reason as logging out: the marketing homepage is Blade, and an
         * Inertia client handed a Blade document paints it inside the shell it
         * was already showing. `Inertia::location()` forces a real page visit.
         */
        return Inertia::location(marketing_url());
    }

    /**
     * Strip the person out of the row and make the login unusable, while leaving the
     * record itself for the bookings that point at it. The email is released so it
     * can be used again.
     */
    private function anonymise(User $user): void
    {
        $user->forceFill([
            'name' => 'Former team member',
            'email' => 'deleted+'.$user->id.'@'.config('booking.deleted_account_domain'),
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'is_active' => false,
            'is_bookable' => false,
            'colour' => null,
        ])->save();

        $user->availabilityRules()->delete();
    }

    private function isLastOwner(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('role', UserRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }
}
