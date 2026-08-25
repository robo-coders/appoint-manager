<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Subject;
use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CustomerPrivacyController extends Controller
{
    public function export(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->load(['subjects', 'bookings']);

        $payload = [
            'customer' => $customer->only(['name', 'email', 'phone', 'notes']),
            'subjects' => $customer->subjects->map->only(['name', 'attributes']),
            'bookings' => $customer->bookings->map->only(['starts_at', 'ends_at', 'status', 'source']),
        ];

        return response(json_encode($payload, JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="customer-'.$customer->id.'.json"',
        ]);
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        DB::transaction(function () use ($customer) {
            Message::query()->where('customer_id', $customer->id)->delete();
            WaitlistEntry::query()->where('customer_id', $customer->id)->delete();
            Booking::query()->where('customer_id', $customer->id)->delete();
            Subject::query()->where('customer_id', $customer->id)->delete();
            $customer->delete();
        });

        return redirect()->route('customers.index')->with('toast', 'Customer record permanently deleted.');
    }
}
