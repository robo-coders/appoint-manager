<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookFailure;
use App\Services\Onboarding\TenantCloner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) {
                $monthStart = now()->startOfMonth();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'plan' => $tenant->plan ?? 'trial',
                    'status' => $tenant->subscription_status,
                    'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                    'is_comped' => $tenant->is_comped,
                    'booking_page_live' => $tenant->booking_page_live,
                    'bookings_this_month' => Booking::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->where('starts_at', '>=', $monthStart)
                        ->count(),
                    'last_activity_at' => $tenant->last_activity_at?->toIso8601String(),
                    'feature_flags' => $tenant->feature_flags ?? [],
                    'preview_url' => $tenant->preview_token
                        ? book_url(null, 'preview/'.$tenant->preview_token)
                        : null,
                ];
            });

        return Inertia::render('SuperAdmin/Index', [
            'tenants' => $tenants,
        ]);
    }

    public function messages(): Response
    {
        $messages = Message::withoutGlobalScopes()
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'channel' => $message->channel instanceof \BackedEnum ? $message->channel->value : $message->channel,
                'type' => $message->type instanceof \BackedEnum ? $message->type->value : $message->type,
                'to' => $message->to,
                'status' => $message->status instanceof \BackedEnum ? $message->status->value : $message->status,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        return Inertia::render('SuperAdmin/Messages', ['messages' => $messages]);
    }

    public function failures(): Response
    {
        return Inertia::render('SuperAdmin/Failures', [
            'failed_jobs' => DB::table('failed_jobs')->orderByDesc('id')->limit(100)->get(),
            'webhook_failures' => WebhookFailure::query()->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function impersonate(Request $request, Tenant $tenant): RedirectResponse
    {
        $owner = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->firstOrFail();

        // The console cannot set a cookie for the app host, so hand off with a
        // short-lived signed link that the app surface exchanges for a session.
        // The audit row is written there, once the handoff is actually redeemed.
        return redirect()->away(
            ImpersonationController::handoffUrl($owner, $request->user()),
        );
    }

    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        $days = max(1, $request->integer('days', 14));
        $tenant->forceFill([
            'trial_ends_at' => ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()
                ? $tenant->trial_ends_at
                : now())->addDays($days),
            'subscription_status' => 'trial',
        ])->save();

        $this->audit($tenant, 'trial.extend', ['days' => $days]);

        return back()->with('toast', 'Trial extended.');
    }

    public function comp(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['is_comped' => true])->save();
        $this->audit($tenant, 'tenant.comp');

        return back()->with('toast', 'Account comped.');
    }

    public function flags(Request $request, Tenant $tenant): RedirectResponse
    {
        $flags = $request->input('feature_flags', []);
        $tenant->forceFill(['feature_flags' => is_array($flags) ? $flags : []])->save();
        $this->audit($tenant, 'tenant.flags', ['flags' => $tenant->feature_flags]);

        return back()->with('toast', 'Flags updated.');
    }

    public function goLive(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['booking_page_live' => true])->save();
        $this->audit($tenant, 'tenant.go_live');

        return back()->with('toast', 'Booking page is live.');
    }

    public function previewLink(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->preview_token) {
            $tenant->forceFill(['preview_token' => (string) Str::uuid()])->save();
        }

        $this->audit($tenant, 'tenant.preview');

        return back()->with('toast', 'Preview link: '.book_url(null, 'preview/'.$tenant->preview_token));
    }

    public function cloneSetup(Request $request, TenantCloner $cloner): RedirectResponse
    {
        $from = Tenant::query()->findOrFail($request->integer('from_tenant_id'));
        $to = Tenant::query()->findOrFail($request->integer('to_tenant_id'));

        $cloner->copy($from, $to);
        $this->audit($to, 'tenant.clone_setup', ['from' => $from->id]);

        return back()->with('toast', 'Setup copied.');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function audit(Tenant $tenant, string $action, array $meta = []): void
    {
        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
