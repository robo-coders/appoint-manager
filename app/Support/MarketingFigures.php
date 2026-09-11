<?php

namespace App\Support;

final class MarketingFigures
{
    public function monthly(): Money
    {
        return new Money((int) config('billing.monthly_price_pence'));
    }

    public function monthlyBare(): string
    {
        return '£'.number_format(intdiv($this->monthly()->amount, 100));
    }

    public function trialDays(): int
    {
        return (int) config('billing.trial_days');
    }

    public function yearlyBare(): string
    {
        return '£'.number_format(intdiv((int) config('billing.yearly_price_pence'), 100));
    }

    public function yearlyLabel(): string
    {
        return (string) config('billing.yearly_label');
    }

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

    public function offerBatch(): int
    {
        return (int) config('booking.waitlist_offer_batch');
    }

    public function offerMinutes(): int
    {
        return (int) config('booking.waitlist_offer_minutes');
    }

    public function vertical(string $key): VerticalFigures
    {
        return new VerticalFigures($key);
    }

    public function contactEmail(): string
    {
        return 'hello@'.(parse_url(Surface::Marketing->url(), PHP_URL_HOST) ?: 'example.com');
    }
}
