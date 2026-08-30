<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Services\Rebooking\RebookAttempts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio's delivery callback: what actually happened to a message we already
 * paid for.
 *
 * Allowance is consumed when the provider *accepts*, and a later `failed` or
 * `undelivered` does not refund it. That is deliberate and unchanged — Twilio
 * bills us on accept, so refunding here would mean absorbing a cost we really
 * incurred. What changes is that the outcome is no longer invisible: the error
 * is recorded against the message so the salon's send log can show her that a
 * number is dead, and a rebooking chase to a dead number counts towards the
 * failure bound so we stop paying to discover the same thing every cycle.
 */
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
            // The claim stays: we were billed and the subject was chased as far
            // as we are able to tell them apart. Only the number's reliability
            // is in question, and that is what this counts.
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
