<?php

namespace App\Http\Middleware;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        $verticalKey = $tenant?->type ?? 'groomer';

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            /*
             * Absolute URLs for the other surfaces. Vue cannot work these out —
             * it must never build a cross-surface link from a bare path, and an
             * Inertia <Link> cannot cross a hostname at all.
             */
            'urls' => [
                'marketing' => marketing_url(),
                'app' => app_url(),
                'admin' => admin_url(),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                ] : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'onboarding_completed' => $tenant->hasCompletedOnboarding(),
                'read_only' => $tenant->isReadOnly(),
                'trial_days_remaining' => $tenant->trialDaysRemaining(),
                'show_trial_banner' => $tenant->onTrial() && $tenant->trialDaysRemaining() <= 7,
            ] : null,
            /*
             * The counts the nav rail right-aligns in mono.
             *
             * A closure, so they are five queries on a full page load and zero
             * on a partial visit — Inertia only evaluates a lazy prop when the
             * client actually asks for it.
             */
            'navCounts' => fn () => $tenant ? $this->navCounts($tenant) : null,
            'impersonating' => (bool) $request->session()->get('impersonator_id'),
            'impersonatedTenant' => $request->session()->get('impersonator_id') ? $tenant?->name : null,
            'vertical' => config('verticals.'.$verticalKey),
            'today' => $tenant
                ? CarbonImmutable::now($tenant->timezone)->toDateString()
                : CarbonImmutable::now()->toDateString(),
            'toast' => fn () => $request->session()->get('toast'),
        ];
    }

    /**
     * What the rail counts, and what it deliberately does not.
     *
     * `Bookings` is upcoming, not all-time: a five-year-old salon with 12,000
     * rows in the table does not want to be told so every time it looks at the
     * sidebar, and "how many appointments are still to come" is the only
     * version of that number anybody acts on.
     *
     * `Waitlist` counts active, unexpired entries — the ones that would
     * actually be texted. Services and staff count what is live, so a
     * deactivated groomer does not inflate the team.
     *
     * There is no count on Diary or Settings. A number that never changes is
     * not information, and the mockup shows neither.
     *
     * @return array<string, int>
     */
    private function navCounts(Tenant $tenant): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'bookings' => Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('starts_at', '>=', $now)
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->count(),
            'customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'waitlist' => WaitlistEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->count(),
            'services' => Service::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->count(),
            'staff' => User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->count(),
        ];
    }
}
