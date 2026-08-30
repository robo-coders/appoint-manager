<?php

namespace App\Services\Sms;

use App\Enums\MessageChannel;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\PhoneNumber;

/**
 * Who has told us to stop texting them.
 *
 * These are UK marketing-adjacent messages to consumers, so the opt-out is a
 * requirement rather than a courtesy, and it has to work without anybody at the
 * salon doing anything. Twilio handles its standard keywords at the number
 * level; we handle them at ours too, because a message we already know is
 * unwanted must never reach the queue in the first place.
 *
 * Two boundaries are deliberate.
 *
 * **Per tenant, not global.** `customers` is already per tenant, so a client of
 * two salons is two rows and opting out of one cannot touch the other. That
 * falls out of the schema rather than being remembered by code.
 *
 * **Marketing only.** A booking confirmation, a reminder, a cancellation and a
 * waitlist offer are service messages the customer asked for by booking, and
 * suppressing them would mean somebody turns up on the wrong day because they
 * once replied STOP. Only the rebooking chase is suppressed. The distinction is
 * `MessageType::isMarketing()`.
 */
final class SmsConsent
{
    public function isOptedOut(Customer $customer): bool
    {
        return $customer->sms_opted_out_at !== null;
    }

    public function optOut(Customer $customer, string $source = 'inbound_sms'): void
    {
        if ($customer->sms_opted_out_at !== null) {
            return;
        }

        $customer->forceFill([
            'sms_opted_out_at' => now(),
            'sms_opt_out_source' => $source,
        ])->save();
    }

    public function optIn(Customer $customer): void
    {
        $customer->forceFill([
            'sms_opted_out_at' => null,
            'sms_opt_out_source' => null,
        ])->save();
    }

    /**
     * What an inbound body means: 'stop', 'start', or nothing we act on.
     *
     * Case-insensitive, trimmed, and with surrounding punctuation stripped,
     * because "STOP." and "stop!" are the same intent and refusing them because
     * of a full stop would be indefensible.
     *
     * @return 'stop'|'start'|null
     */
    public function classify(string $body): ?string
    {
        $word = trim(mb_strtolower(trim($body)), " \t\n\r\0\x0B.,!?;:'\"()[]");

        if (in_array($word, array_map('strval', (array) config('rebooking.opt_out_keywords')), true)) {
            return 'stop';
        }

        if (in_array($word, array_map('strval', (array) config('rebooking.opt_in_keywords')), true)) {
            return 'start';
        }

        return null;
    }

    /**
     * Which salon is this person replying to?
     *
     * Inbound SMS arrives on one platform number shared by every tenant, so the
     * webhook payload cannot say. The most recent SMS we sent to that number
     * can: a STOP is a reply to the last thing that arrived. If we have never
     * texted the number there is nothing to opt out of, and we say so rather
     * than guessing.
     *
     * The alternative — opting the number out of every tenant that holds it —
     * was rejected. It is the wrong answer to "opt-out is per tenant" and it
     * lets one salon's client silence another salon's messages.
     *
     * @return array{0: Tenant, 1: Customer}|null
     */
    public function resolve(string $from): ?array
    {
        $normalised = $this->normalise($from);

        if ($normalised === null) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([$normalised, $from])));

        $message = Message::withoutGlobalScopes()
            ->where('channel', MessageChannel::Sms->value)
            ->whereIn('to', $candidates)
            ->whereNotNull('customer_id')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            return null;
        }

        $tenant = Tenant::query()->find($message->tenant_id);
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->whereKey($message->customer_id)
            ->first();

        if ($tenant === null || $customer === null) {
            return null;
        }

        return [$tenant, $customer];
    }

    private function normalise(string $raw): ?string
    {
        try {
            return PhoneNumber::toE164($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
