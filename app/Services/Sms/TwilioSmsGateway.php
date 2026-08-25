<?php

namespace App\Services\Sms;

use Twilio\Rest\Client;

final class TwilioSmsGateway implements SmsGateway
{
    public function send(string $toE164, string $body): string
    {
        $client = new Client(
            (string) config('services.twilio.sid'),
            (string) config('services.twilio.token'),
        );

        $params = [
            'from' => config('services.twilio.from'),
            'body' => $body,
        ];

        $statusUrl = config('services.twilio.status_webhook_url');

        if (is_string($statusUrl) && $statusUrl !== '') {
            $params['statusCallback'] = $statusUrl;
        }

        $message = $client->messages->create($toE164, $params);

        return (string) $message->sid;
    }
}
