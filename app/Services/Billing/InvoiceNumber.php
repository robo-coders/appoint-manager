<?php

namespace App\Services\Billing;

use App\Models\BillingCounter;
use Illuminate\Support\Facades\DB;

class InvoiceNumber
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->year;
        $prefix = (string) config('billing.invoice_prefix');

        $value = DB::transaction(function () {
            $row = BillingCounter::query()
                ->where('name', 'invoice')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                BillingCounter::query()->insert(['name' => 'invoice', 'value' => 0]);
                $row = BillingCounter::query()
                    ->where('name', 'invoice')
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $next = $row->value + 1;
            $row->forceFill(['value' => $next])->save();

            return $next;
        });

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }
}
