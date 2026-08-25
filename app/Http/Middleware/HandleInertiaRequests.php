<?php

namespace App\Http\Middleware;

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
            'impersonating' => (bool) $request->session()->get('impersonator_id'),
            'preview' => fn () => $request->session()->get('import_preview'),
            'vertical' => config('verticals.'.$verticalKey),
            'today' => $tenant
                ? CarbonImmutable::now($tenant->timezone)->toDateString()
                : CarbonImmutable::now()->toDateString(),
            'toast' => fn () => $request->session()->get('toast'),
        ];
    }
}
