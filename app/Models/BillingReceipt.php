<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingReceipt extends Model
{
    protected $fillable = [
        'tenant_id',
        'stripe_invoice_id',
        'invoice_number',
        'amount',
        'currency',
        'status',
        'issued_at',
        'pdf_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isDeclined(): bool
    {
        return $this->status === 'declined';
    }
}
