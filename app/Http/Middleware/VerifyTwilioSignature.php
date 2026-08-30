<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Twilio's `X-Twilio-Signature`, checked the way the other two webhooks check
 * theirs.
 *
 * AUDIT H7 recorded `/twilio/status` as unverified, which was survivable while
 * the endpoint only moved a message row from `sent` to `delivered`. It stops
 * being survivable now that `/twilio/inbound` can set a consent flag: an
 * unauthenticated caller who knows a phone number could opt a salon's client
 * out of the messages that salon is paying for.
 *
 * The scheme is HMAC-SHA1 over the full request URL with the POST parameters
 * appended in key order, keyed on the account auth token — so it needs no
 * shared secret beyond the one already in `TWILIO_TOKEN`.
 *
 * Skipped when no token is configured, which is every local and test
 * environment and is what keeps this from being a wall in front of the suite.
 * Production has a token, so production verifies.
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.twilio.token');

        if ($token === '' || ! (bool) config('services.twilio.verify_signature', true)) {
            return $next($request);
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');

        if ($signature === '' || ! hash_equals($this->expected($request, $token), $signature)) {
            Log::warning('Rejected a Twilio webhook with a bad signature', [
                'path' => $request->path(),
                'signed' => $signature !== '',
            ]);

            abort(403);
        }

        return $next($request);
    }

    private function expected(Request $request, string $token): string
    {
        $params = $request->post();
        ksort($params);

        $payload = $request->fullUrl();

        foreach ($params as $key => $value) {
            $payload .= $key.(is_scalar($value) ? (string) $value : '');
        }

        return base64_encode(hash_hmac('sha1', $payload, $token, true));
    }
}
