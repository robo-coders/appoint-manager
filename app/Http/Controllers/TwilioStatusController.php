<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Services\Rebooking\RebookAttempts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioStatusController extends Controller
{
    public function __invoke(Request $request, RebookAttempts $attempts): Response
    {
        $sid = (string) $request->input('MessageSid');
        $status = strtolower((string) $request->input('MessageStatus'));

        if ($sid === '') {
            return response('ok', 200);
        }

        $message = Message::withoutGlobalScopes()->where('provider_id', $sid)->first();

        if ($message === null) {
            return response('ok', 200);
        }

        $mapped = match ($status) {
            'delivered' => MessageStatus::Delivered,
            'failed' => MessageStatus::Failed,
            'undelivered' => MessageStatus::Undelivered,
            default => MessageStatus::Sent,
        };

        $message->forceFill([
            'status' => $mapped,
            'provider_error' => $this->error($request),
        ])->save();

        if (in_array($mapped, [MessageStatus::Failed, MessageStatus::Undelivered], true)) {
            $attempts->reportUndelivered($message);
        }

        if ($mapped === MessageStatus::Delivered) {
            $attempts->succeeded($message);
        }

        return response('ok', 200);
    }

    private function error(Request $request): ?string
    {
        $code = trim((string) $request->input('ErrorCode'));
        $text = trim((string) $request->input('ErrorMessage'));

        return match (true) {
            $code !== '' && $text !== '' => mb_substr($code.' '.$text, 0, 191),
            $code !== '' => $code,
            $text !== '' => mb_substr($text, 0, 191),
            default => null,
        };
    }
}
