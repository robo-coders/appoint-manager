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
    /**
     * The list, and everything the edit form needs to open pre-filled.
     *
     * It used to select `key` and `label` only, which is why there was no edit
     * screen: the page had never been sent the rest of the definition. The two
     * JSON columns are the reason this matters — a vertical's subject fields and
     * default services are the whole of what makes it a *trade* rather than a
     * word in a dropdown.
     *
     * `tenants_count` is the delete guard's evidence, counted once here rather
     * than per row. Soft-deleted tenants are excluded: a tenant in the bin is
     * not using the vertical, and counting it would leave a key undeletable
     * forever with nothing on screen to explain why.
     */
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

    /**
     * The two JSON columns are defaults here, not overrides.
     *
     * They used to be written *after* the spread — `'subject_fields' => []` and
     * `'default_services' => []`, unconditionally — which threw away whatever
     * had been submitted and is the bug this method existed to demonstrate.
     * Listing them first keeps the old behaviour for a caller that sends
     * neither (the columns are nullable, and `[]` is a truer "none" than NULL)
     * while letting a caller that sends them win.
     */
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
        // `key` is `prohibited` on the request, so `validated()` cannot carry
        // one even if the form is edited in the browser.
        $vertical->update($request->validated());

        return redirect()->route('super-admin.verticals')->with('toast', 'Vertical updated.');
    }

    /**
     * Delete, and the one case where it refuses.
     *
     * A vertical in use is not deletable. `tenants.type` holds the key as a
     * plain string with no foreign key behind it, so a delete would succeed at
     * the database and take effect as a *silent* change to live salons: every
     * tenant on that key falls through `Vertical::definitionFor()` to the
     * groomer row, and a barber's booking page starts asking his customers for
     * the dog's breed. There is no cascade to write and no confirmation strong
     * enough to make that a reasonable thing to allow, so it is blocked at the
     * only place it can be — here, with the count in the message.
     *
     * Soft-blocked rather than hidden: the button is on screen and says why,
     * because "why can I not delete this" is the question the alternative
     * leaves.
     */
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
