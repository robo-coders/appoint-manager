<?php

namespace App\Services\Billing;

use App\Models\BillingReceipt;
use App\Support\DesignTokens;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReceiptPdf
{
    public function path(BillingReceipt $receipt): string
    {
        if ($receipt->pdf_path && Storage::disk('local')->exists($receipt->pdf_path)) {
            return $receipt->pdf_path;
        }

        $receipt->loadMissing('tenant');
        $tenant = $receipt->tenant;
        $vatKey = (string) config('billing.tenant_vat_key');
        $vatNumber = is_string(data_get($tenant?->settings, $vatKey))
            ? (string) data_get($tenant->settings, $vatKey)
            : '';

        $html = view('pdf.invoice', [
            'receipt' => $receipt,
            'tenant' => $tenant,
            'product' => (string) config('product.name'),
            'seller' => $this->seller(),
            'amount' => (new Money($receipt->amount, strtoupper($receipt->currency)))->formatted(),
            'vat_number' => $vatNumber !== '' ? $vatNumber : null,
            'plan_name' => (string) config('billing.plan_name'),
            'card' => $tenant?->card_last4 ? '•••• '.$tenant->card_last4 : null,
            'ink' => DesignTokens::value('ink'),
            'paper' => DesignTokens::value('paper'),
            'accent' => DesignTokens::value('accent'),
            'inkMuted' => DesignTokens::value('ink-2'),
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $relative = 'receipts/'.$receipt->invoice_number.'.pdf';

        Storage::disk('local')->put($relative, $pdf->output());

        $receipt->forceFill(['pdf_path' => $relative])->save();

        return $relative;
    }

    /** @return array{legal_name: string, address: string, company_number: string, vat_number: string} */
    private function seller(): array
    {
        $seller = config('billing.seller', []);

        return [
            'legal_name' => (string) ($seller['legal_name'] ?: config('product.name')),
            'address' => (string) ($seller['address'] ?? ''),
            'company_number' => (string) ($seller['company_number'] ?? ''),
            'vat_number' => (string) ($seller['vat_number'] ?? ''),
        ];
    }
}
