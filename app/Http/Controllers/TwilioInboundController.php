<?php

namespace App\Http\Controllers;

use App\Services\Sms\SmsConsent;
use App\Services\Sms\SmsGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Inbound SMS, which in this product means exactly one thing: STOP.
 *
 * Twilio suppresses its own standard keywords at the number level, so a client
 * who replies STOP stops receiving our texts whether or not this endpoint
 * exists. It exists anyway, for two reasons. We must never *queue* a message we
 * already know is unwanted — a message Twilio silently drops is one we have
 * still counted, still logged as sent, and still shown the salon as a chase
 * that happened. And a number-level block is invisible to the operator, so she
 * would keep seeing a client on the overdue list with no way to know why the
 * texts are not landing.
 *
 * Always answers 200. A webhook that returns 500 is a webhook Twilio retries
 * and then disables, and there is nothing a client can send here that is our
 * error rather than theirs.
 */
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
            // Nobody is expected to reply to these. Recorded at debug rather
            // than dropped, because "a client texted us back" is a thing the
            // salon may one day want surfaced, and a silent discard is how you
            // find out too late that they have been trying to reach you.
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

        // Empty by default: Twilio already acknowledges its own keywords on the
        // number, and a second confirmation is a segment spent telling somebody
        // who asked us to stop texting them that we have stopped texting them.
        if ($reply !== '' && $customer->phone) {
            $sms->send($customer->phone, $reply);
        }

        return response('ok', 200);
    }
}
