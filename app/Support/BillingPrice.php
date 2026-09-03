<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * One place that turns billing config (and a tenant override) into a figure.
 *
 * List price lives in `config/billing.php`. What we charge a given salon is
 * `tenants.monthly_price_override_pence` when it is set. The pricing page and
 * the register sentence read the list. Checkout reads the tenant.
 */
final class BillingPrice
{
    public static function listMonthlyPence(): int
    {
        return (int) config('billing.monthly_price_pence');
    }

    public static function listYearlyPence(): int
    {
        return (int) config('billing.yearly_price_pence');
    }

    public static function forTenant(Tenant $tenant): int
    {
        return $tenant->monthly_price_override_pence ?? self::listMonthlyPence();
    }

    public static function topUpPence(): int
    {
        return (int) config('billing.sms_topup_price_pence');
    }

    /** Whole pounds where the price is whole pounds. `£29`, not `£29.00`. */
    public static function formatPence(int $pence): string
    {
        return '£'.($pence % 100 === 0
            ? (string) intdiv($pence, 100)
            : number_format($pence / 100, 2, '.', ','));
    }

    public static function money(int $pence): Money
    {
        return new Money($pence);
    }
}
