<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreVerticalRequest;
use App\Models\Vertical;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VerticalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Verticals', [
            'verticals' => Vertical::query()
                ->orderBy('label')
                ->get(['key', 'label'])
                ->map(fn (Vertical $vertical) => [
                    'key' => $vertical->key,
                    'label' => $vertical->label,
                ])
                ->all(),
        ]);
    }

    public function store(StoreVerticalRequest $request): RedirectResponse
    {
        Vertical::query()->create([
            ...$request->validated(),
            'subject_fields' => [],
            'default_services' => [],
        ]);

        return redirect()->route('super-admin.verticals')->with('toast', 'Vertical created.');
    }
}
