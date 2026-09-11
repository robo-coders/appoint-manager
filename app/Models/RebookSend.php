<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RebookSend extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'subject_id',
        'customer_id',
        'message_id',
        'due_on',
        'attempt',
        'segments',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'attempt' => 'integer',
            'segments' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
