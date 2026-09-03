<?php

namespace App\Support;

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
 * So nothing here is a literal. The price and the trial come from
 * `config('billing')`, and the waitlist's batch size and window come from
 * `config('booking')`. If somebody changes what the product charges, the page
 * that sells it changes with it.
 *
 * **Nothing here knows what trade the reader is in.** Every figure on this
 * object is true on every page of the site, which is what makes it safe to hand
 * to the shared header, footer and home page. Anything that is only true for
 * one vertical — a price list, a service name, the word "dog" — lives on
 * `VerticalFigures` and is reachable only from a vertical's own page.
 */
final class MarketingFigures
{
    /** What we charge, a month. `£29.00`. */
    public function monthly(): Money
    {
        return new Money((int) config('billing.monthly_price_pence'));
    }

    /**
     * The same figure with the pence dropped, for the places it is set large
     * and the `.00` would be two characters of noise in the biggest type on the
     * page. Derived, not restated.
     */
    public function monthlyBare(): string
    {
        return '£'.number_format(intdiv($this->monthly()->amount, 100));
    }

    public function trialDays(): int
    {
        return (int) config('billing.trial_days');
    }

    /** What we charge, a year, in whole pounds. `£290`. */
    public function yearlyBare(): string
    {
        return '£'.number_format(intdiv((int) config('billing.yearly_price_pence'), 100));
    }

    public function yearlyLabel(): string
    {
        return (string) config('billing.yearly_label');
    }

    /**
     * The yearly price as a monthly figure, for the comparison table.
     *
     * Divided here rather than added to `config/billing.php` as a third price:
     * there are two Stripe prices and there must be two numbers in config. A
     * third would be a figure nothing charges, free to drift from the two that
     * do.
     */
    public function yearlyPerMonthBare(): string
    {
        return '£'.number_format(intdiv(intdiv((int) config('billing.yearly_price_pence'), 12), 100));
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

    public function smsCeiling(): int
    {
        return (int) config('billing.sms_hard_ceiling');
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
     * One vertical's own words and prices, for that vertical's trade page.
     *
     * A method rather than a constructor argument, because the same shared
     * `$figures` object reaches every page and only one page is allowed to ask
     * this question.
     */
    public function vertical(string $key): VerticalFigures
    {
        return new VerticalFigures($key);
    }

    /**
     * Where a data request or a sales question goes.
     *
     * Built from the marketing host rather than `app.url`, because under
     * subdomain routing those are two different names and the address on the
     * privacy policy has to be the one on the apex domain.
     */
    public function contactEmail(): string
    {
        return 'hello@'.(parse_url(Surface::Marketing->url(), PHP_URL_HOST) ?: 'example.com');
    }
}
