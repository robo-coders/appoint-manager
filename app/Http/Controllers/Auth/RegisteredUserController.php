<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Support\SetupSteps;
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
        return Inertia::render('Auth/Register', [
            'terms' => sprintf(
                'No card. %d days free, then %s a month, and you can stop at any point.',
                (int) config('billing.trial_days'),
                self::pounds((int) config('billing.monthly_price_pence')),
            ),
            'steps' => SetupSteps::all(),
            'businessTypes' => Vertical::query()
                ->orderBy('label')
                ->get()
                ->map(fn (Vertical $vertical) => [
                    'value' => $vertical->key,
                    'label' => $vertical->label,
                ])
                ->values()
                ->all(),
        ]);
    }

    private static function pounds(int $pence): string
    {
        return '£'.($pence % 100 === 0 ? (string) intdiv($pence, 100) : number_format($pence / 100, 2));
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $tenant = Tenant::query()->create([
                'name' => $request->validated('business_name'),
                'slug' => TenantSlug::generate($request->validated('business_name')),
                'type' => $request->validated('business_type'),
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
