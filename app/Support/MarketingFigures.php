<?php

namespace App\Support;

use App\Models\Vertical;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Every number the marketing site prints, read from the config that the product
 * actually runs on.
 *
 * This exists because the old site typed its figures out by hand and
 * DECISIONS.md had to record it three times: `marketing/pricing.blade.php`
 * hardcoded `£39` and `£390`, `BillingController::index` hardcoded `'£39'`, and
 * the home page's no-show sum hardcoded a £45 slot — three copies of two
 * numbers that agreed by coincidence. A price change would have been correct in
 * Stripe, correct in the app, and wrong on the page selling it.
 *
 * So nothing here is a literal. `--price` comes from `config('billing')`, the
 * slot price comes from the vertical's own seeded price list, and the waitlist's
 * batch size and window come from `config('booking')`. If somebody changes what
 * the product charges, the page that sells it changes with it.
 *
 * **The one figure deliberately not here is the competitor's per-booking fee.**
 * It is unverified, it must carry an inline `UNVERIFIED` comment on the exact
 * line that prints it, and an HTML comment cannot travel inside a PHP method.
 * It stays in `pricing.blade.php`, next to its marker, where a grep for
 * UNVERIFIED finds the figure and the caveat in one hit.
 */
final class MarketingFigures
{
    /**
     * The seeded service the arithmetic is built on.
     *
     * A medium full groom is the modal grooming appointment and the one the
     * vertical seeds at 90 minutes, so it is the slot a cancellation actually
     * costs. Matched by name against the groomer vertical rather than copied,
     * so this cannot drift from what a new tenant is given on day one.
     */
    private const SLOT_SERVICE = 'Full groom — medium dog';

    private const VERTICAL = 'groomer';

    /** What we charge, a month. `£29.00`. */
    public function monthly(): Money
    {
        return new Money((int) config('billing.monthly_price_pence'));
    }

    /**
     * The same figure with the pence dropped, for the one place it is set at
     * 34px and the `.00` would be two characters of noise in the largest type
     * on the page. Derived, not restated.
     */
    public function monthlyBare(): string
    {
        return '£'.number_format(intdiv($this->monthly()->amount, 100));
    }

    public function trialDays(): int
    {
        return (int) config('billing.trial_days');
    }

    /** What we charge, a year. `£290.00`. */
    public function yearly(): Money
    {
        return new Money((int) config('billing.yearly_price_pence'));
    }

    public function yearlyBare(): string
    {
        return '£'.number_format(intdiv($this->yearly()->amount, 100));
    }

    public function yearlyLabel(): string
    {
        return (string) config('billing.yearly_label');
    }

    public function smsIncluded(): int
    {
        return (int) config('billing.sms_included');
    }

    public function smsTopupSize(): int
    {
        return (int) config('billing.sms_topup_size');
    }

    public function smsTopupBare(): string
    {
        return '£'.number_format(intdiv((int) config('billing.sms_topup_price_pence'), 100));
    }

    public function depositBare(): string
    {
        return '£'.number_format(intdiv($this->deposit()->amount, 100));
    }

    /** The seeded price of one medium full groom. `£45.00`. */
    public function slot(): Money
    {
        return new Money($this->seededService()['price']);
    }

    /** The seeded deposit that holds it. `£10.00`. */
    public function deposit(): Money
    {
        return new Money($this->seededService()['deposit_amount']);
    }

    public function slotName(): string
    {
        return self::SLOT_SERVICE;
    }

    public function slotMinutes(): int
    {
        return (int) $this->seededService()['duration_minutes'];
    }

    /**
     * What is left of one refilled slot after the month is paid for.
     *
     * This is the whole argument on the home page and it is subtraction, done
     * here, from two figures the reader can see: one slot, minus one month.
     * Never a hardcoded difference — a price change has to move this or the
     * page starts lying.
     */
    public function surplus(): Money
    {
        return new Money(max(0, $this->slot()->amount - $this->monthly()->amount));
    }

    /** True while one refilled slot still covers a month. */
    public function oneRefillCovers(): bool
    {
        return $this->slot()->amount >= $this->monthly()->amount;
    }

    /** How many people get the text. `config('booking.waitlist_offer_batch')`. */
    public function offerBatch(): int
    {
        return (int) config('booking.waitlist_offer_batch');
    }

    /** How long they have to claim it. `config('booking.waitlist_offer_minutes')`. */
    public function offerMinutes(): int
    {
        return (int) config('booking.waitlist_offer_minutes');
    }

    /**
     * The whole seeded grooming price list, for the trade page.
     *
     * This is the actual list a new grooming tenant is given on day one, read
     * from the groomer vertical. The trade page shows it rather than a
     * plausible-looking invention, which means the page and the product cannot
     * disagree about what a salon starts with.
     *
     * @return list<array{name: string, minutes: int, price: Money, deposit: Money}>
     */
    public function seededPriceList(): array
    {
        $services = (array) Vertical::definitionFor(self::VERTICAL)['default_services'];

        return array_map(fn (array $service) => [
            'name' => (string) $service['name'],
            'minutes' => (int) $service['duration_minutes'],
            'price' => new Money((int) $service['price']),
            'deposit' => new Money((int) $service['deposit_amount']),
        ], array_values($services));
    }

    /**
     * What the vertical calls things, so the trade page uses the product's own
     * words rather than a second set written for marketing.
     *
     * @return array<string, string>
     */
    public function verticalWords(): array
    {
        $vertical = Vertical::definitionFor(self::VERTICAL);

        return [
            'label' => (string) ($vertical['label'] ?? 'Dog grooming'),
            'subject' => (string) ($vertical['subject_singular'] ?? 'dog'),
            'subjects' => (string) ($vertical['subject_plural'] ?? 'dogs'),
            'customer' => (string) ($vertical['customer_singular'] ?? 'client'),
        ];
    }

    /**
     * The extra fields a grooming tenant's booking page asks for, as a sentence.
     *
     * "breed, size, coat type and temperament / notes" — built here rather than
     * assembled in the template, because a comma-and-"and" list written as a
     * Blade loop is four `@if`s and an off-by-one waiting to happen. The labels
     * are the vertical's own; only the case is changed, because they are
     * sentence-cased as form labels and land mid-sentence here.
     */
    public function subjectFieldList(): string
    {
        $fields = (array) Vertical::definitionFor(self::VERTICAL)['subject_fields'];

        $labels = array_map(
            fn (array $field) => lcfirst((string) $field['label']),
            array_values($fields),
        );

        return Arr::join($labels, ', ', ' and ');
    }

    /**
     * @return array{name: string, duration_minutes: int, price: int, deposit_amount: int}
     */
    private function seededService(): array
    {
        $services = (array) Vertical::definitionFor(self::VERTICAL)['default_services'];

        foreach ($services as $service) {
            if (($service['name'] ?? null) === self::SLOT_SERVICE) {
                /** @var array{name: string, duration_minutes: int, price: int, deposit_amount: int} $service */
                return $service;
            }
        }

        /*
         * Loud rather than zero. A silent fallback here prints "£0.00 covers
         * £29.00" on the home page, which is worse than a 500 on a page nobody
         * has visited yet.
         */
        throw new RuntimeException(
            sprintf('the %s vertical has no "%s" in its price list.', self::VERTICAL, self::SLOT_SERVICE),
        );
    }
}
