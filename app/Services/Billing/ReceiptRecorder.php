<?php

namespace App\Services\Billing;

use App\Models\BillingReceipt;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class ReceiptRecorder
{
    public function __construct(private InvoiceNumber $numbers) {}

    /** @param  array<string, mixed>  $invoice */
    public function record(Tenant $tenant, array $invoice, string $status): BillingReceipt
    {
        $stripeId = (string) ($invoice['id'] ?? '');

        $existing = BillingReceipt::query()->where('stripe_invoice_id', $stripeId)->first();

        if ($existing) {
            $existing->forceFill([
                'status' => $status,
                'amount' => $this->amount($invoice, $status),
            ])->save();

            return $existing;
        }

        $issued = isset($invoice['created'])
            ? CarbonImmutable::createFromTimestamp((int) $invoice['created'])
            : now();

        return BillingReceipt::query()->create([
            'tenant_id' => $tenant->id,
            'stripe_invoice_id' => $stripeId,
            'invoice_number' => $this->numbers->next((int) $issued->year),
            'amount' => $this->amount($invoice, $status),
            'currency' => (string) ($invoice['currency'] ?? 'gbp'),
            'status' => $status,
            'issued_at' => $issued,
        ]);
    }

    /** @param  array<string, mixed>  $invoice */
    private function amount(array $invoice, string $status): int
    {
        if ($status === 'paid') {
            return (int) ($invoice['amount_paid'] ?? $invoice['amount_due'] ?? 0);
        }

        return (int) ($invoice['amount_due'] ?? $invoice['amount_paid'] ?? 0);
    }
}
