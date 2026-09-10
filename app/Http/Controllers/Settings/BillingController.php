<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\BillingPreviewException;
use App\Exceptions\PaymentsNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingReceipt;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\BillingPageData;
use App\Services\Billing\ReceiptPdf;
use App\Services\Billing\UnconfiguredBillingGateway;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends Controller
{
    public function show(BillingGateway $billing, BillingPageData $page): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        return Inertia::render('Settings/Billing/Index', [
            'billing' => $page->for($tenant, ! $billing instanceof UnconfiguredBillingGateway),
        ]);
    }

    public function preview(Request $request, BillingGateway $billing): JsonResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $interval = $this->interval($request);

        try {
            return response()->json($billing->previewSwap($tenant, $interval));
        } catch (BillingPreviewException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (PaymentsNotConfiguredException) {
            return response()->json(['error' => 'We could not price this change. Try again in a moment.'], 422);
        }
    }

    public function swap(Request $request, BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $billing->swap($tenant, $this->interval($request));
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => 'billing.swap',
            'meta' => ['interval' => $this->interval($request)],
        ]);

        return back();
    }

    public function cancel(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $billing->cancelAtPeriodEnd($tenant);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => 'billing.cancel_at_period_end',
        ]);

        return back();
    }

    public function resume(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $billing->resumeCancellation($tenant);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        return back();
    }

    public function refresh(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $billing->refresh($tenant);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        return back();
    }

    public function setupIntent(BillingGateway $billing): JsonResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            return response()->json(['client_secret' => $billing->createSetupIntent($tenant)]);
        } catch (PaymentsNotConfiguredException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function paymentMethod(Request $request, BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $id = $request->string('payment_method')->toString();
        abort_unless($id !== '', 422);

        try {
            $billing->confirmPaymentMethod($tenant, $id);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        return back();
    }

    public function checkout(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            return redirect()->away($billing->checkoutUrl($tenant, 'monthly'));
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }
    }

    public function download(BillingReceipt $billingReceipt, ReceiptPdf $pdf): StreamedResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $billingReceipt->tenant_id === $tenant->id, 403);

        $path = $pdf->path($billingReceipt);
        $filename = $billingReceipt->invoice_number.'.pdf';

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function export(): StreamedResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $filename = 'invoices-'.now($tenant->timezone)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($tenant) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['invoice_number', 'date', 'amount', 'status']);

            BillingReceipt::query()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('issued_at')
                ->each(function (BillingReceipt $receipt) use ($handle, $tenant) {
                    fputcsv($handle, [
                        $receipt->invoice_number,
                        $receipt->issued_at?->timezone($tenant->timezone)->format('Y-m-d'),
                        (new Money($receipt->amount, strtoupper($receipt->currency)))->formatted(),
                        $receipt->isDeclined() ? 'Payment declined' : 'Paid',
                    ]);
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function interval(Request $request): string
    {
        $interval = $request->string('interval')->toString();

        return in_array($interval, ['yearly', 'year'], true) ? 'yearly' : 'monthly';
    }

    private function unreachable(): RedirectResponse
    {
        return back()->withErrors([
            'billing' => 'Card payments are not set up on this installation yet. Get in touch and we will sort it out.',
        ]);
    }
}
