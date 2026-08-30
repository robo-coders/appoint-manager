<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Billing\SmsAllowance;
use App\Services\Rebooking\RebookAttempts;
use App\Services\Sms\SmsGateway;
use App\Support\SmsSegments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one SMS and records the outcome.
 *
 * This exists so a Twilio outage can never take down whatever caused the message.
 * A booking must not be lost because a text could not be delivered, and a refund
 * must not be rolled back because the confirmation SMS failed.
 *
 * Allowance is consumed only after the provider accepts the message, and in
 * segments rather than messages. A throw here retries; `failed()` marks the row
 * failed, consumes nothing, and gives a rebooking claim back so the next run
 * retries the subject instead of leaving them uncontacted forever.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    /**
     * Note what is *not* here: a call to `RebookAttempts::succeeded()`. Twilio
     * accepting a message says nothing about whether a handset received it, and
     * clearing the failure counter on accept would mean a permanently dead
     * number resets its own history every cycle and is dialled forever. Only a
     * `delivered` callback clears it.
     */
    public function handle(SmsGateway $sms, SmsAllowance $allowance, RebookAttempts $attempts): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $tenant = Tenant::query()->find($message->tenant_id);
        $segments = max(1, (int) ($message->segments ?: SmsSegments::count((string) $message->body)));

        if ($tenant === null || ! $allowance->canSend($tenant, $segments)) {
            $message->forceFill(['status' => MessageStatus::Failed])->save();
            $attempts->release($message);

            return;
        }

        $providerId = $sms->send($message->to, $message->body);

        $message->forceFill([
            'provider_id' => $providerId,
            'status' => MessageStatus::Sent,
            'segments' => $segments,
        ])->save();

        $allowance->consume($tenant->fresh(), $segments);
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $message->forceFill(['status' => MessageStatus::Failed])->save();

        // The provider would not take it. The subject was not chased, so the
        // claim on this due cycle has to go back — otherwise a single bad
        // afternoon at Twilio means nobody is ever chased again.
        app(RebookAttempts::class)->release($message);
    }
}
