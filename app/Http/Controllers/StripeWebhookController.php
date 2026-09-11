<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use App\Models\WebhookFailure;
use App\Services\Stripe\StripeGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe): Response
    {
        try {
            $event = $stripe->constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (RuntimeException $exception) {
            WebhookFailure::query()->create([
                'source' => 'connect',
                'message' => $exception->getMessage(),
            ]);

            return response($exception->getMessage(), 400);
        }

        try {
            $row = StripeEvent::query()->create([
                'event_id' => $event['id'],
                'account_id' => $event['account'] ?? null,
                'type' => $event['type'],
                'payload' => $event['data'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200);
        } catch (Throwable $exception) {
            report($exception);

            $this->recordFailure($event, $exception);

            return response('could not store event', 500);
        }

        ProcessStripeEvent::dispatch($row->id);

        return response('ok', 200);
    }

    /** @param  array<string, mixed>  $event */
    private function recordFailure(array $event, Throwable $exception): void
    {
        try {
            WebhookFailure::query()->create([
                'source' => 'connect',
                'event_id' => $event['id'] ?? null,
                'type' => $event['type'] ?? null,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
        }
    }
}
