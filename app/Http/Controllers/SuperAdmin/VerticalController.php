<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreVerticalRequest;
use App\Http\Requests\SuperAdmin\UpdateVerticalRequest;
use App\Models\Tenant;
use App\Models\Vertical;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VerticalController extends Controller
{
    public function index(): Response
    {
        $counts = Tenant::query()
            ->selectRaw('type, count(*) as aggregate')
            ->whereNotNull('type')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return Inertia::render('SuperAdmin/Verticals', [
            'verticals' => Vertical::query()
                ->orderBy('label')
                ->get()
                ->map(fn (Vertical $vertical) => [
                    'id' => $vertical->id,
                    'key' => $vertical->key,
                    'label' => $vertical->label,
                    'subject_singular' => $vertical->subject_singular,
                    'subject_plural' => $vertical->subject_plural,
                    'customer_singular' => $vertical->customer_singular,
                    'appointment_singular' => $vertical->appointment_singular,
                    'subject_fields' => $vertical->subject_fields ?? [],
                    'default_services' => $vertical->default_services ?? [],
                    'tenants_count' => (int) ($counts[$vertical->key] ?? 0),
                ])
                ->all(),
        ]);
    }

    public function store(StoreVerticalRequest $request): RedirectResponse
    {
        Vertical::query()->create([
            'subject_fields' => [],
            'default_services' => [],
            ...$request->validated(),
        ]);

        return redirect()->route('super-admin.verticals')->with('toast', 'Vertical created.');
    }

    public function update(UpdateVerticalRequest $request, Vertical $vertical): RedirectResponse
    {
        $vertical->update($request->validated());

        return redirect()->route('super-admin.verticals')->with('toast', 'Vertical updated.');
    }

    public function destroy(Vertical $vertical): RedirectResponse
    {
        $inUse = Tenant::query()->where('type', $vertical->key)->count();

        if ($inUse > 0) {
            return back()->with('toast', $inUse === 1
                ? 'One salon is set up as '.$vertical->label.'. Move it to another business type first.'
                : $inUse.' salons are set up as '.$vertical->label.'. Move them to another business type first.');
        }

        $vertical->delete();

        return redirect()->route('super-admin.verticals')->with('toast', 'Vertical deleted.');
    }
}
