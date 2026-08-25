<?php

namespace App\Http\Controllers;

use App\Http\Requests\Services\ReorderServicesRequest;
use App\Http\Requests\Services\StoreServiceRequest;
use App\Http\Requests\Services\UpdateServiceRequest;
use App\Models\Service;
use App\Models\User;
use App\Support\ServicePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Service::class);

        $services = Service::query()
            ->with('staff')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => ServicePayload::toArray($service));

        return Inertia::render('Services/Index', [
            'services' => $services,
            'staff' => $this->assignableStaff(),
        ]);
    }

    public function show(Service $service): Response
    {
        $this->authorize('view', $service);

        return Inertia::render('Services/Show', [
            'service' => ServicePayload::toArray($service),
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $service = DB::transaction(function () use ($request) {
            $max = (int) Service::query()->max('sort_order');

            $service = Service::query()->create([
                ...$request->safe()->except('staff_ids'),
                'buffer_minutes' => $request->integer('buffer_minutes'),
                'is_active' => $request->boolean('is_active', true),
                'sort_order' => $max + 1,
            ]);

            $service->staff()->sync($request->input('staff_ids', []));

            return $service;
        });

        return redirect()->route('services.index')->with('toast', 'Service saved.');
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        DB::transaction(function () use ($request, $service) {
            $service->update($request->safe()->except('staff_ids'));

            if ($request->exists('staff_ids')) {
                $service->staff()->sync($request->input('staff_ids', []));
            }
        });

        return redirect()->route('services.index')->with('toast', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        $service->delete();

        return redirect()->route('services.index')->with('toast', 'Service removed.');
    }

    public function reorder(ReorderServicesRequest $request): RedirectResponse
    {
        foreach ($request->validated('ids') as $order => $id) {
            Service::query()->whereKey($id)->update(['sort_order' => $order]);
        }

        return redirect()->route('services.index');
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function assignableStaff(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
