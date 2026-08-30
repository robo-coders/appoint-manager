<?php

namespace App\Models;

use App\Support\BrandPalette;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'type',
        'timezone',
        'currency',
        'brand_colour',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'postcode',
        'settings',
        'stripe_account_id',
        'stripe_onboarding_complete',
        'stripe_requirements',
        'platform_fee_bps',
        'country',
        'trial_ends_at',
        'onboarding_completed_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'plan',
        'dunning_started_at',
        'dunning_emails_sent',
        'paused_at',
        'cancelled_at',
        'cancellation_reason',
        'is_comped',
        'booking_page_live',
        'preview_token',
        'last_activity_at',
        'feature_flags',
        'sms_cycle_used',
        'sms_prepaid',
        'sms_included_override',
        'sms_ceiling_override',
        'sms_killed_at',
        'sms_cycle_started_at',
        'sms_warnings_sent',
        'monthly_price_override_pence',
    ];

    /**
     * Every tenant is born on a trial, whichever door it came through.
     *
     * `subscription_status` defaults to `trial` in the schema and
     * `trial_ends_at` defaults to NULL, and `hasAdminWriteAccess()` reads the
     * *date* rather than the status. So a tenant created any way other than
     * through `RegisteredUserController` — the demo seeder, a tinker
     * `firstOrCreate`, a support script, an import — arrived with write access
     * already lapsed, and its owner's first login was a read-only diary behind
     * "Admin is read-only until billing is up to date". Not a hypothetical:
     * `DemoTenantSeeder` and `scripts/e2e-setup.sh` both make tenants this way,
     * and both were being repaired by hand afterwards.
     *
     * **Why here and not a column default.** The trial length is
     * `config('billing.trial_days')`, and a database cannot read the config.
     * MySQL will not accept an expression default on a `TIMESTAMP` beyond
     * `CURRENT_TIMESTAMP` either, so a column default could say "now" but never
     * "now plus thirty days" — and "now" is an expired trial, which is the bug.
     * A `creating` hook is the one place that runs for every Eloquent write, in
     * every environment, with the config loaded. It fills only when the caller
     * has left the value null, so `TenantFactory`, the registration flow and
     * `DemoDataSeeder::billing()` all keep the last word on a value they set on
     * purpose.
     *
     * What this does not cover is a raw `DB::table('tenants')->insert()`.
     * Nothing does one, and a raw insert also skips the slug, the uuid and
     * every cast, so it is not a path anybody reaches by accident.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if ($tenant->trial_ends_at === null) {
                $tenant->trial_ends_at = now()->addDays((int) config('billing.trial_days'));
            }

            if ($tenant->sms_cycle_started_at === null) {
                $tenant->sms_cycle_started_at = now();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'stripe_onboarding_complete' => 'boolean',
            'stripe_requirements' => 'array',
            'platform_fee_bps' => 'integer',
            'trial_ends_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'dunning_started_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'is_comped' => 'boolean',
            'booking_page_live' => 'boolean',
            'dunning_emails_sent' => 'integer',
            'feature_flags' => 'array',
            'sms_cycle_used' => 'integer',
            'sms_prepaid' => 'integer',
            'sms_included_override' => 'integer',
            'sms_ceiling_override' => 'integer',
            'sms_killed_at' => 'datetime',
            'sms_cycle_started_at' => 'datetime',
            'sms_warnings_sent' => 'array',
            'monthly_price_override_pence' => 'integer',
        ];
    }

    /**
     * The CSS custom property this tenant's booking page paints with, or null
     * when it has not chosen one and should inherit ink.
     *
     * Goes through BrandPalette so a value that is somehow no longer one of the
     * six — a preset renamed in tokens.css, a row edited by hand — degrades to
     * ink rather than emitting a `var(--brand-whatever)` that resolves to
     * nothing and paints an invisible button.
     */
    public function brandVariable(): ?string
    {
        return BrandPalette::variable($this->brand_colour);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * @return list<string>
     */
    public function onboardingCompletedSteps(): array
    {
        $steps = $this->settings['onboarding']['completed_steps'] ?? [];

        return array_values(array_filter($steps, fn ($step) => is_string($step)));
    }

    public function markOnboardingStep(string $step): void
    {
        $settings = $this->settings ?? [];
        $completed = $settings['onboarding']['completed_steps'] ?? [];

        if (! in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        $settings['onboarding']['completed_steps'] = array_values($completed);
        $this->settings = $settings;

        if ($step === 'hours') {
            $this->onboarding_completed_at = now();
        }

        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function vertical(): array
    {
        return config('verticals.'.$this->type, config('verticals.groomer'));
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function slotGranularityMinutes(): int
    {
        return (int) data_get($this->settings, 'booking.slot_granularity_minutes', config('booking.slot_granularity_minutes'));
    }

    public function minNoticeHours(): int
    {
        return (int) data_get($this->settings, 'booking.min_notice_hours', config('booking.min_notice_hours'));
    }

    public function horizonDays(): int
    {
        return (int) data_get($this->settings, 'booking.horizon_days', config('booking.horizon_days'));
    }

    public function takesDeposits(): bool
    {
        return $this->stripe_onboarding_complete && filled($this->stripe_account_id);
    }

    public function smsEnabled(): bool
    {
        return (bool) data_get($this->settings, 'notifications.sms_enabled', true);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture()
            && ! in_array($this->subscription_status, ['active', 'paused'], true);
    }

    public function trialDaysRemaining(): int
    {
        if ($this->trial_ends_at === null || $this->trial_ends_at->isPast()) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->trial_ends_at->startOfDay());
    }

    public function hasAdminWriteAccess(): bool
    {
        if ($this->is_comped) {
            return true;
        }

        if (in_array($this->subscription_status, ['active', 'paused'], true)) {
            return true;
        }

        if ($this->subscription_status === 'past_due' && $this->dunning_started_at) {
            return $this->dunning_started_at->copy()->addDays((int) config('billing.dunning_days'))->isFuture();
        }

        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function isReadOnly(): bool
    {
        return ! $this->hasAdminWriteAccess();
    }
}
