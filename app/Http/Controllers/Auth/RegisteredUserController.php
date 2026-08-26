<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSlug;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $tenant = Tenant::query()->create([
                'name' => $request->validated('business_name'),
                'slug' => TenantSlug::generate($request->validated('business_name')),
                'type' => 'groomer',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'email' => $request->validated('email'),
                'trial_ends_at' => now()->addDays((int) config('billing.trial_days')),
                'subscription_status' => 'trial',
                'booking_page_live' => false,
                'preview_token' => (string) Str::uuid(),
            ]);

            $owner = new User;
            $owner->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'role' => UserRole::Owner,
                'is_bookable' => true,
                'is_active' => true,
            ])->save();

            return $owner;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('onboarding.show');
    }
}
