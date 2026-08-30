<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Rebooking\RebookAttempts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rebooking chase we have committed to sending.
 *
 * A row here is a claim on a (subject, due cycle, attempt) slot. It exists
 * before the message is queued and it is deleted if the provider rejects the
 * send, so tomorrow's run retries rather than skipping the subject forever.
 *
 * @see RebookAttempts
 */
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'attempt' => 'integer',
            'segments' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
