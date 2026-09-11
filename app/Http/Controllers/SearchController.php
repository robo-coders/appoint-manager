<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\ContactVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $q = trim((string) $request->query('q', ''));
        $contacts = ContactVisibility::for($request->user());

        $customers = Customer::query()
            ->when($q !== '', function ($query) use ($q, $contacts) {
                $query->where(function ($inner) use ($q, $contacts) {
                    $inner->where('name', 'like', '%'.$q.'%');

                    if ($contacts->unrestricted()) {
                        $inner->orWhere('email', 'like', '%'.$q.'%');
                    }
                });
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $contacts->customer($customer) ? $customer->email : null,
            ])->all(),
        ]);
    }
}
