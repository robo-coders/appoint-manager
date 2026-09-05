<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateLoyaltyRequest;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Services\Loyalty\Loyalty;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loyalty packages, in settings.
 *
 * A fourth settings tab rather than a toggle buried on the business screen: the
 * feature is a switch *and* a definition — a count and a reward — and a form
 * that only exists when a switch is on does not belong halfway down a page about
 * the salon's postcode.
 *
 * **Off is the default and off is inert.** The flag lives in
 * `tenants.settings['loyalty']['enabled']`, beside `notifications.sms_enabled`,
 * so switching it on needed no migration. Everything downstream asks
 * `Loyalty::enabled()` first, so a tenant that has never opened this screen has
 * the feature absent rather than merely hidden.
 */
class LoyaltyController extends Controller
{
    public function __construct(private Loyalty $loyalty) {}

    public function edit(): Response
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        $package = $this->loyalty->activePackage($tenant)
            // `activePackage()` returns null when the feature is off, which
            // would empty the form the moment somebody switched it off — so the
            // screen reads the row directly and lets the toggle decide what is
            // shown. Turning it off and on again keeps what was typed.
            ?? LoyaltyPackage::query()->where('is_active', true)->latest('id')->first();

        return Inertia::render('Settings/Loyalty', [
            'loyalty' => [
                'enabled' => $this->loyalty->enabled($tenant),
                'name' => $package?->name,
                'sessions_required' => $package?->sessions_required,
                'reward' => $package?->reward,
                /*
                 * How many customers are part-way through. It is the one number
                 * that makes switching the feature off a decision rather than a
                 * click — those cards stop filling.
                 */
                'enrolled' => LoyaltyEnrolment::query()->count(),
            ],
        ]);
    }

    public function update(UpdateLoyaltyRequest $request): RedirectResponse
    {
        $tenant = current_tenant();

        abort_unless($tenant, 403);

        $data = $request->validated();
        $settings = $tenant->settings ?? [];
        $settings['loyalty']['enabled'] = (bool) $data['enabled'];
        $tenant->forceFill(['settings' => $settings])->save();

        if ((bool) $data['enabled']) {
            /*
             * One active package per tenant in v1, so this updates the existing
             * row rather than adding a second. `updateOrCreate` on `is_active`
             * is what keeps that true through a rename: a salon changing five
             * sessions to six is editing its scheme, not starting a new one, and
             * every customer's progress is against the row rather than the
             * number.
             */
            LoyaltyPackage::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'is_active' => true],
                [
                    'name' => $data['name'],
                    'sessions_required' => (int) $data['sessions_required'],
                    'reward' => $data['reward'],
                ],
            );
        }

        return redirect()->route('settings.loyalty.edit')->with('toast', 'Changes saved.');
    }
}
