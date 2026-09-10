<?php

namespace App\Services\Billing;

class DeclineReason
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'insufficient_funds' => 'the bank reported insufficient funds',
        'card_declined' => 'your bank declined the charge',
        'expired_card' => 'your card has expired',
        'lost_card' => 'your bank declined the charge',
        'stolen_card' => 'your bank declined the charge',
        'generic_decline' => 'your bank declined the charge',
    ];

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function fromInvoice(array $invoice, BillingGateway $billing): string
    {
        $code = $this->code($invoice, $billing);

        return self::MAP[$code] ?? 'your bank declined the payment';
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function code(array $invoice, BillingGateway $billing): string
    {
        foreach ($this->embeddedCodes($invoice) as $code) {
            if ($code !== '') {
                return $code;
            }
        }

        foreach ($this->chargeIds($invoice) as $id) {
            $fetched = $billing->paymentFailureCode($id);

            if (is_string($fetched) && $fetched !== '') {
                return $fetched;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return list<string>
     */
    private function embeddedCodes(array $invoice): array
    {
        $codes = [];

        foreach ($this->chargeObjects($invoice) as $charge) {
            $fromCharge = (string) ($charge['failure_code'] ?? data_get($charge, 'outcome.reason') ?? '');

            if ($fromCharge !== '') {
                $codes[] = $fromCharge;
            }
        }

        $candidates = [
            data_get($invoice, 'last_finalization_error.decline_code'),
            data_get($invoice, 'last_payment_error.decline_code'),
            data_get($invoice, 'payment_intent.last_payment_error.decline_code'),
            data_get($invoice, 'payments.data.0.payment.payment_intent.last_payment_error.decline_code'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $codes[] = $candidate;
            }
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return list<array<string, mixed>>
     */
    private function chargeObjects(array $invoice): array
    {
        $objects = [];

        foreach ($this->chargeCandidates($invoice) as $candidate) {
            if (is_array($candidate) && ($candidate['object'] ?? '') === 'charge') {
                $objects[] = $candidate;

                continue;
            }

            if (is_array($candidate) && (isset($candidate['failure_code']) || isset($candidate['outcome']))) {
                $objects[] = $candidate;
            }
        }

        return $objects;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return list<string>
     */
    private function chargeIds(array $invoice): array
    {
        $ids = [];

        foreach ($this->chargeCandidates($invoice) as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $ids[] = $candidate;
            }

            if (is_array($candidate) && is_string($candidate['id'] ?? null) && ($candidate['id'] ?? '') !== '') {
                $ids[] = $candidate['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return list<mixed>
     */
    private function chargeCandidates(array $invoice): array
    {
        return [
            $invoice['charge'] ?? null,
            data_get($invoice, 'payment_intent.latest_charge'),
            data_get($invoice, 'payments.data.0.payment.charge'),
            data_get($invoice, 'payments.data.0.payment.payment_intent'),
            data_get($invoice, 'last_finalization_error.charge'),
            $invoice['payment_intent'] ?? null,
        ];
    }
}
