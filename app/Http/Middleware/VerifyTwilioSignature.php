<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
