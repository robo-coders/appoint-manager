<?php

namespace App\Services\Sms;

final class RecordingSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, body: string, id: string}> */
    public array $sent = [];

    public function send(string $toE164, string $body): string
    {
        $id = 'rec-'.count($this->sent);
        $this->sent[] = [
            'to' => $toE164,
            'body' => $body,
            'id' => $id,
        ];

        return $id;
    }
}
