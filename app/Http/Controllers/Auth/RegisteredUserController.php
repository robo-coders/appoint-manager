<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
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
            /*
             * What it costs, as a sentence, built from config.
             *
             * The first draft of this page said "£29 a month", which is wrong —
             * the plan is £39 and `config/billing.php` has said so all along.
             * A price typed into a template is a price that disagrees with the
             * pricing page the week somebody changes one of them.
             */
            'terms' => sprintf(
                'No card. %d days free, then %s a month, and you can stop at any point.',
                (int) config('billing.trial_days'),
                self::pounds((int) config('billing.monthly_price_pence')),
            ),
            /*
             * The whole flow, from here. Registration is step one of five, and
             * a person on it should be able to see the other four before they
             * decide to start. See `App\Support\SetupSteps`.
             */
            'steps' => SetupSteps::all(),
        ]);
    }

    /** Whole pounds where the price is whole pounds, which every plan is. */
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
