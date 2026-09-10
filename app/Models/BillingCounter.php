<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCounter extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
