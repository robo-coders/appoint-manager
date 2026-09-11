<?php

namespace App\Services\Sms;

use App\Enums\MessageChannel;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\PhoneNumber;

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

    /** @return array{0: Tenant, 1: Customer}|null */
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
