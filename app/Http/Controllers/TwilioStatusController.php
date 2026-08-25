<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioStatusController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $sid = (string) $request->input('MessageSid');
        $status = strtolower((string) $request->input('MessageStatus'));

        if ($sid === '') {
            return response('ok', 200);
        }

        $message = Message::withoutGlobalScopes()->where('provider_id', $sid)->first();

        if ($message !== null) {
            $mapped = match ($status) {
                'delivered' => MessageStatus::Delivered,
                'failed' => MessageStatus::Failed,
                'undelivered' => MessageStatus::Undelivered,
                default => MessageStatus::Sent,
            };
            $message->forceFill(['status' => $mapped])->save();
        }

        return response('ok', 200);
    }
}
