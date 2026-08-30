<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Billing\SmsAllowance;
use App\Services\Sms\SmsGateway;
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
 * Allowance is consumed only after the provider accepts the message. A throw
 * here retries; `failed()` marks the row failed and does not consume.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    public function handle(SmsGateway $sms, SmsAllowance $allowance): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $tenant = Tenant::query()->find($message->tenant_id);

        if ($tenant === null || ! $allowance->canSend($tenant)) {
            $message->forceFill(['status' => MessageStatus::Failed])->save();

            return;
        }

        $providerId = $sms->send($message->to, $message->body);

        $message->forceFill([
            'provider_id' => $providerId,
            'status' => MessageStatus::Sent,
        ])->save();

        $allowance->consume($tenant->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        Message::withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->update(['status' => MessageStatus::Failed->value]);
    }
}
