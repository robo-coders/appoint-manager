<?php

namespace App\Http\Middleware;

use App\Enums\BookingStatus;
use App\Enums\ThemePreference;
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
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        $verticalKey = $tenant?->type ?? 'groomer';

        return [
            ...parent::share($request),
            'appName' => config('product.name'),
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
                    'theme_preference' => ($user->theme_preference ?? ThemePreference::System)->value,
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
                'is_beta' => $tenant->is_beta,
                'trial_days_remaining' => $tenant->trialDaysRemaining(),
                'show_trial_banner' => $tenant->onTrial() && $tenant->trialDaysRemaining() <= 7,
            ] : null,
            'navCounts' => fn () => $tenant ? $this->navCounts($tenant) : null,
            'ui' => [
                'mobile_breakpoint' => (int) config('ui.mobile_breakpoint'),
                'rail_collapsed_ceiling' => (int) config('ui.rail_collapsed_ceiling'),
                'toast_duration_ms' => (int) config('ui.toast_duration_ms'),
            ],
            'impersonating' => (bool) $request->session()->get('impersonator_id'),
            'impersonatedTenant' => $request->session()->get('impersonator_id') ? $tenant?->name : null,
            'vertical' => fn () => Vertical::definitionFor($verticalKey),
            'today' => $tenant
                ? CarbonImmutable::now($tenant->timezone)->toDateString()
                : CarbonImmutable::now()->toDateString(),
            'toast' => fn () => $request->session()->get('toast'),
            'createdBooking' => fn () => $request->session()->get('created_booking'),
            'sms' => fn () => $tenant ? app(SmsAllowance::class)->snapshot($tenant) : null,
        ];
    }

    /** @return array<string, int> */
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
