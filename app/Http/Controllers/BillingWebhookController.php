<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBillingEvent;
use App\Models\BillingEvent;
use App\Models\WebhookFailure;
use App\Services\Billing\BillingGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, BillingGateway $billing): Response
    {
        try {
            $event = $billing->constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (RuntimeException $exception) {
            WebhookFailure::query()->create([
                'source' => 'billing',
                'message' => $exception->getMessage(),
            ]);

            return response($exception->getMessage(), 400);
        }

        try {
            $row = BillingEvent::query()->create([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'payload' => $event['data'] ?? $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200);
        } catch (Throwable $exception) {
            report($exception);

            try {
                WebhookFailure::query()->create([
                    'source' => 'billing',
                    'event_id' => $event['id'] ?? null,
                    'type' => $event['type'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
                // Storage is the failure. The 500 is the signal.
            }

            return response('could not store event', 500);
        }

        ProcessBillingEvent::dispatch($row->id);

        return response('ok', 200);
    }
}
