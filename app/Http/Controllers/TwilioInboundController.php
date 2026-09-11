<?php

namespace App\Http\Controllers;

use App\Services\Sms\SmsConsent;
use App\Services\Sms\SmsGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwilioInboundController extends Controller
{
    public function __invoke(Request $request, SmsConsent $consent, SmsGateway $sms): Response
    {
        $from = trim((string) $request->input('From'));
        $body = (string) $request->input('Body');

        if ($from === '') {
            return response('ok', 200);
        }

        $intent = $consent->classify($body);

        if ($intent === null) {
            Log::debug('Inbound SMS with no recognised keyword', ['from' => $from]);

            return response('ok', 200);
        }

        $resolved = $consent->resolve($from);

        if ($resolved === null) {
            Log::info('Inbound opt-out from a number we have never texted', ['intent' => $intent]);

            return response('ok', 200);
        }

        [$tenant, $customer] = $resolved;

        if ($intent === 'stop') {
            $consent->optOut($customer, 'inbound_sms');
            $reply = (string) config('rebooking.opt_out_reply');
        } else {
            $consent->optIn($customer);
            $reply = (string) config('rebooking.opt_in_reply');
        }

        Log::info('SMS consent changed by inbound message', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'intent' => $intent,
        ]);

        if ($reply !== '' && $customer->phone) {
            try {
                $sms->send($customer->phone, $reply);
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Consent reply could not be sent', [
                    'tenant_id' => $tenant->id,
                    'customer_id' => $customer->id,
                ]);
            }
        }

        return response('ok', 200);
    }
}
