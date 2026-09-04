<?php

namespace App\Http\Middleware;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Models\WaitlistEntry;
use App\Services\Billing\SmsAllowance;
use App\Services\Rebooking\OverdueSubjects;
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
            /*
             * The product's name, for the chrome and the page title. From
             * `product.name` and never `app.name` — that one is the machine
             * identity and slugs into the session cookie names.
             */
            'appName' => config('product.name'),
            /*
             * Absolute URLs for the other surfaces. Vue cannot work these out —
             * it must never build a cross-surface link from a bare path, and an
             * Inertia <Link> cannot cross a hostname at all.
             */
            /*
             * The one sentence the auth surface's quiet column carries.
             *
             * Here rather than in `GuestLayout.vue` because it is customer-facing
             * copy and this product builds those in PHP — and because it is the
             * product's own definition of itself out of DESIGN.md, which should
             * exist once. It is shared with every request rather than passed per
             * screen: six auth screens all show the same panel, and six copies of
             * one sentence is five chances for them to disagree.
             */
            /*
             * A failed sign-in that is not a validation error — stale CSRF,
             * dropped session. Login.vue paints this as a Callout. Flash, so
             * it survives the Inertia::location bounce off a 419.
             */
            'authNotice' => $request->session()->get('auth_notice'),
            'auth_panel' => [
                'headline' => 'The diary, the deposits, and the people who did not turn up.',
                'body' => config('product.name').' is appointment software for small businesses '
                    .'that lose money when clients do not arrive. One diary, one place, and a '
                    .'deposit taken before the appointment rather than chased after it.',
            ],
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
            'vertical' => fn () => Vertical::definitionFor($verticalKey),
            'today' => $tenant
                ? CarbonImmutable::now($tenant->timezone)->toDateString()
                : CarbonImmutable::now()->toDateString(),
            'toast' => fn () => $request->session()->get('toast'),
            /*
             * The diary's optimistic row is reconciled from this, not from
             * waiting for the redirected page's bookings list to replace it.
             */
            'createdBooking' => fn () => $request->session()->get('created_booking'),
            'sms' => fn () => $tenant ? app(SmsAllowance::class)->snapshot($tenant) : null,
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
                ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
                ->count(),
            'customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'overdue' => app(OverdueSubjects::class)->summary($tenant)['count'],
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
