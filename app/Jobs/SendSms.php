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

class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

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

        app(RebookAttempts::class)->release($message);
    }
}
