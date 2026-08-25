<?php

namespace App\Services\Sms;

interface SmsGateway
{
    public function send(string $toE164, string $body): string;
}
