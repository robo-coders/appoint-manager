<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

final class LogSmsGateway implements SmsGateway
{
    public function send(string $toE164, string $body): string
    {
        $id = 'log-'.uniqid();

        Log::info('sms.send', [
            'to' => $toE164,
            'body' => $body,
            'id' => $id,
        ]);

        return $id;
    }
}
