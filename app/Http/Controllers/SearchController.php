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

                    // Matching on the address would confirm an address that the
                    // result is then not allowed to print. See CustomerController.
                    if ($contacts->unrestricted()) {
                        $inner->orWhere('email', 'like', '%'.$q.'%');
                    }
                });
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);

        /*
         * The palette prints the address as each hit's second line. Stripped
         * here rather than hidden there — a JSON endpoint is the easiest thing
         * in the product to read directly.
         */
        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $contacts->customer($customer) ? $customer->email : null,
            ])->all(),
        ]);
    }
}
