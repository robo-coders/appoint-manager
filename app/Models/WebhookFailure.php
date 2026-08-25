<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookFailure extends Model
{
    protected $fillable = [
        'source',
        'event_id',
        'type',
        'message',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
