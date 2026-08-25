<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $staff = User::query()
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_bookable' => $user->is_bookable,
                'is_active' => $user->is_active,
                'colour' => $user->colour,
            ]);

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        User::query()->create([
            ...$request->safe()->all(),
            'password' => Str::password(32),
            'role' => UserRole::Staff,
            'is_bookable' => $request->boolean('is_bookable', true),
            'is_active' => true,
            'colour' => $request->input('colour', '#71717A'),
        ]);

        return redirect()->route('staff.index')->with('toast', 'Staff saved.');
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $staff->update($request->validated());

        return redirect()->route('staff.index')->with('toast', 'Staff updated.');
    }
}
