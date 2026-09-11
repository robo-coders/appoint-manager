<?php

namespace App\Http\Controllers;

use App\Enums\LoyaltyCardStatus;
use App\Http\Requests\Settings\UpdateLoyaltyRequest;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltyStamp;
use App\Models\Service;
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
                'eligible_service_id' => $package?->eligible_service_id,
                'auto_stamp' => $package?->auto_stamp ?? true,
                'auto_enrol' => $package?->auto_enrol ?? true,
                'auto_apply_reward' => $package?->auto_apply_reward ?? true,
                'show_visit_date' => $package?->show_visit_date ?? true,
                /*
                 * How many customers are part-way through. It is the one number
                 * that makes switching the feature off a decision rather than a
                 * click — those cards stop filling.
                 */
                'enrolled' => LoyaltyEnrolment::query()->count(),
            ],
            'services' => Service::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Service $service) => ['value' => $service->id, 'label' => $service->name])
                ->all(),
            'limits' => [
                'min_visits' => (int) config('loyalty.min_visits_required'),
                'max_visits' => (int) config('loyalty.max_visits_required'),
                'max_reward_length' => (int) config('loyalty.max_reward_description_length'),
                'default_name' => (string) config('loyalty.default_name'),
                'default_visits' => (int) config('loyalty.default_visits_required'),
            ],
            'progress' => LoyaltyEnrolment::query()
                ->selectRaw('stamps_used, COUNT(*) as cards')
                ->groupBy('stamps_used')
                ->pluck('cards', 'stamps_used')
                ->all(),
            'preview' => [
                'visit_dates' => LoyaltyStamp::query()
                    ->orderByDesc('visit_date')
                    ->limit((int) config('loyalty.max_visits_required'))
                    ->pluck('visit_date')
                    ->map(fn ($date) => $date->toDateString())
                    ->all(),
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
            $current = LoyaltyPackage::query()->where('is_active', true)->latest('id')->first();

            /*
             * One active package per tenant in v1, so this updates the existing
             * row rather than adding a second. `updateOrCreate` on `is_active`
             * is what keeps that true through a rename: a salon changing five
             * sessions to six is editing its scheme, not starting a new one, and
             * every customer's progress is against the row rather than the
             * number.
             */
            $package = LoyaltyPackage::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'is_active' => true],
                [
                    'name' => $data['name'],
                    'sessions_required' => (int) $data['sessions_required'],
                    'reward' => $data['reward'],
                    'eligible_service_id' => $data['eligible_service_id'] ?? null,
                    'auto_stamp' => (bool) ($data['auto_stamp'] ?? $current?->auto_stamp ?? true),
                    'auto_enrol' => (bool) ($data['auto_enrol'] ?? $current?->auto_enrol ?? true),
                    'auto_apply_reward' => (bool) ($data['auto_apply_reward'] ?? $current?->auto_apply_reward ?? true),
                    'show_visit_date' => (bool) ($data['show_visit_date'] ?? $current?->show_visit_date ?? true),
                ],
            );

            $this->completeCardsThatNowQualify($package);
        }

        return redirect()->route('settings.loyalty.edit')->with('toast', 'Changes saved.');
    }

    private function completeCardsThatNowQualify(LoyaltyPackage $package): void
    {
        LoyaltyEnrolment::query()
            ->where('loyalty_package_id', $package->id)
            ->where('status', LoyaltyCardStatus::Active->value)
            ->where('stamps_used', '>=', $package->sessions_required)
            ->update([
                'stamps_used' => $package->sessions_required,
                'status' => LoyaltyCardStatus::StampedOut->value,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
