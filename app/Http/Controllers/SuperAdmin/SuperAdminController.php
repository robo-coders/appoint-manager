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
use App\Services\Billing\SmsAllowance;
use App\Services\Onboarding\TenantCloner;
use App\Support\BillingPrice;
use Carbon\CarbonImmutable;
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
        $monthStart = now()->startOfMonth();

        /*
         * Owners in one query rather than one per row. The screen names the
         * person before it borrows their session, so it needs them — and a
         * hundred salons is a hundred round trips if this is done in the map.
         */
        $owners = User::withoutGlobalScopes()
            ->where('role', 'owner')
            ->get()
            ->keyBy('tenant_id');

        $tenants = Tenant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($monthStart, $owners) {
                $trialEnds = $tenant->trial_ends_at;

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'plan' => $tenant->plan ?? 'trial',
                    'status' => $tenant->subscription_status,
                    'trial_ends_at' => $trialEnds?->toDateString(),
                    'trial_days_left' => $trialEnds ? (int) now()->startOfDay()->diffInDays($trialEnds->startOfDay(), false) : null,
                    'is_comped' => $tenant->is_comped,
                    'booking_page_live' => $tenant->booking_page_live,
                    'bookings_this_month' => Booking::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->where('starts_at', '>=', $monthStart)
                        ->count(),
                    'last_activity_at' => $tenant->last_activity_at?->toIso8601String(),
                    'last_seen_label' => $this->lastSeen($tenant),
                    'owner_name' => $owners->get($tenant->id)?->name,
                    'state' => $this->state($tenant),
                    'needs_attention' => $this->needsAttention($tenant),
                    'feature_flags' => $tenant->feature_flags ?? [],
                    'preview_url' => $tenant->preview_token
                        ? book_url(null, 'preview/'.$tenant->preview_token)
                        : null,
                    'booking_url' => $tenant->publicBookingUrl(),
                    'sms' => app(SmsAllowance::class)->snapshot($tenant),
                    'monthly_price' => BillingPrice::formatPence(BillingPrice::forTenant($tenant)),
                    'monthly_price_override_pence' => $tenant->monthly_price_override_pence,
                    'sms_included_override' => $tenant->sms_included_override,
                    'sms_ceiling_override' => $tenant->sms_ceiling_override,
                    'sms_killed' => $tenant->sms_killed_at !== null,
                ];
            });

        return Inertia::render('SuperAdmin/Index', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * What this salon's billing actually is, as a phrase.
     *
     * The screen used to render `plan`, `subscription_status` and `is_comped`
     * side by side separated by spaces, so "trial past_due" was a cell you had
     * to parse rather than read. These are the states, in the order they matter,
     * and the phrase is built here because it is customer-facing copy — even
     * when the customer is us.
     */
    private function state(Tenant $tenant): string
    {
        $trialEnds = $tenant->trial_ends_at;

        return match (true) {
            $tenant->is_comped => 'Comped',
            $tenant->subscription_status === 'past_due' => 'Payment failed',
            $tenant->subscription_status === 'canceled' => 'Cancelled',
            $tenant->subscription_status === 'cancelled' => 'Cancelled',
            $tenant->subscription_status === 'paused' => 'Paused',
            $tenant->subscription_status === 'active' => 'Subscribed',
            $trialEnds !== null && $trialEnds->isPast() => 'Trial over',
            $trialEnds !== null => 'Trial',
            default => 'No plan',
        };
    }

    /**
     * Moving a trial date is not putting a paying salon onto a trial.
     *
     * @return array{subscription_status?: string}
     */
    private function trialStatusUnlessPaying(Tenant $tenant): array
    {
        if ($tenant->subscription_status === 'active') {
            return [];
        }

        return ['subscription_status' => 'trial'];
    }

    /**
     * Is this one of the salons worth looking at first?
     *
     * The screen opens sorted on this rather than alphabetically. A hundred
     * salons in name order is a directory; the question at 2am is which of them
     * is broken.
     */
    private function needsAttention(Tenant $tenant): bool
    {
        if ($tenant->is_comped || $tenant->subscription_status === 'active') {
            return false;
        }

        return in_array($tenant->subscription_status, ['past_due', 'canceled', 'cancelled'], true)
            || ($tenant->trial_ends_at !== null && $tenant->trial_ends_at->isPast());
    }

    /**
     * "3 days ago", not an ISO 8601 string.
     *
     * The column was rendering `2026-08-24T09:12:00+01:00` — thirty characters
     * of which two are the answer. A salon that has not opened the app in a
     * fortnight is the fact; the timestamp is not.
     */
    private function lastSeen(Tenant $tenant): string
    {
        return $tenant->last_activity_at?->diffForHumans(['short' => true]) ?? 'Never';
    }

    public function messages(): Response
    {
        $names = Tenant::query()->pluck('name', 'id');

        $messages = Message::withoutGlobalScopes()
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'tenant_name' => $names->get($message->tenant_id),
                'channel' => $message->channel instanceof \BackedEnum ? $message->channel->value : $message->channel,
                'type' => $message->type instanceof \BackedEnum ? $message->type->value : $message->type,
                'to' => $message->to,
                'status' => $message->status instanceof \BackedEnum ? $message->status->value : $message->status,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
                /*
                 * "3h ago", not thirty characters of ISO 8601 of which two are
                 * the answer. The exact instant is still on the row as
                 * `created_at` for anything that needs to sort or parse it.
                 */
                'sent_label' => $message->created_at?->diffForHumans(['short' => true]) ?? '—',
            ]);

        return Inertia::render('SuperAdmin/Messages', ['messages' => $messages]);
    }

    public function failures(): Response
    {
        /*
         * The columns a person actually reads, pulled out of the payload here
         * rather than dumped into a `<pre>` on the page.
         *
         * `failed_jobs.payload` is a serialised job — several hundred lines of
         * escaped closure — and `exception` is the full stack trace. Neither is
         * what you want at 2am: you want the class, the message, and the name of
         * the job, and then you go and read the code. The full trace is still in
         * the table for anyone who needs it.
         */
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                $payload = json_decode((string) $row->payload, true);
                $exception = (string) $row->exception;
                $failedAt = CarbonImmutable::parse($row->failed_at);

                return [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'job_name' => $payload['displayName'] ?? ($payload['job'] ?? 'Unknown job'),
                    'exception_class' => Str::before($exception, ':') ?: 'Throwable',
                    // The first line only. The rest is the stack.
                    'exception_message' => Str::limit(trim(Str::after(Str::before($exception, "\n"), ':')), 200),
                    'failed_label' => $failedAt->diffForHumans(['short' => true]),
                ];
            });

        $hooks = WebhookFailure::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (WebhookFailure $failure): array => [
                'id' => $failure->id,
                'source' => $failure->source,
                'type' => $failure->type,
                'message' => $failure->message,
                'received_label' => $failure->created_at?->diffForHumans(['short' => true]) ?? '—',
            ]);

        return Inertia::render('SuperAdmin/Failures', [
            'failed_jobs' => $jobs,
            'webhook_failures' => $hooks,
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
            /*
             * Extending a date is not the same as putting a paying salon onto
             * a trial. The demo tenant is `active` with a leftover trial end
             * still in the future; writing `trial` here used to demote it on
             * the console to "Trial" the moment we added fourteen days.
             */
            ...($this->trialStatusUnlessPaying($tenant)),
        ])->save();

        $this->audit($tenant, 'trial.extend', ['days' => $days]);

        return back()->with('toast', 'Trial extended.');
    }

    public function setTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        if ($request->boolean('end')) {
            $tenant->forceFill([
                'trial_ends_at' => now()->subSecond(),
                'subscription_status' => 'trial',
            ])->save();
            $this->audit($tenant, 'trial.end');

            return back()->with('toast', 'Trial ended.');
        }

        if ($request->filled('ends_at')) {
            $ends = CarbonImmutable::parse((string) $request->input('ends_at'))->endOfDay();
            $tenant->forceFill([
                'trial_ends_at' => $ends,
                ...($this->trialStatusUnlessPaying($tenant)),
            ])->save();
            $this->audit($tenant, 'trial.set', ['ends_at' => $ends->toDateString()]);

            return back()->with('toast', 'Trial set to '.$ends->toFormattedDateString().'.');
        }

        $days = $request->integer('days', 14);
        $base = ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture())
            ? $tenant->trial_ends_at
            : now();
        $tenant->forceFill([
            'trial_ends_at' => $base->copy()->addDays($days),
            ...($this->trialStatusUnlessPaying($tenant)),
        ])->save();
        $this->audit($tenant, 'trial.set', ['days' => $days]);

        return back()->with('toast', $days < 0 ? 'Trial shortened.' : 'Trial extended.');
    }

    public function setAllowance(Request $request, Tenant $tenant): RedirectResponse
    {
        $value = $request->filled('sms_included_override')
            ? max(0, $request->integer('sms_included_override'))
            : null;
        $tenant->forceFill(['sms_included_override' => $value])->save();
        $this->audit($tenant, 'sms.allowance', ['included' => $value]);

        return back()->with('toast', 'Allowance updated.');
    }

    public function setCeiling(Request $request, Tenant $tenant): RedirectResponse
    {
        $value = $request->filled('sms_ceiling_override')
            ? max(0, $request->integer('sms_ceiling_override'))
            : null;
        $tenant->forceFill(['sms_ceiling_override' => $value])->save();
        $this->audit($tenant, 'sms.ceiling', ['ceiling' => $value]);

        return back()->with('toast', 'Ceiling updated.');
    }

    public function killSms(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['sms_killed_at' => now()])->save();
        $this->audit($tenant, 'sms.kill');

        return back()->with('toast', 'SMS stopped for this salon.');
    }

    public function resumeSms(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['sms_killed_at' => null])->save();
        $this->audit($tenant, 'sms.resume');

        return back()->with('toast', 'SMS allowed again.');
    }

    public function grantSms(Request $request, Tenant $tenant, SmsAllowance $sms): RedirectResponse
    {
        $credits = max(1, $request->integer('credits', (int) config('billing.sms_topup_size')));
        $sms->grant($tenant, $credits);
        $this->audit($tenant, 'sms.grant', ['credits' => $credits]);

        return back()->with('toast', $credits.' texts granted. Stripe was not charged.');
    }

    public function setPrice(Request $request, Tenant $tenant): RedirectResponse
    {
        $pence = $request->filled('monthly_price_override_pence')
            ? max(0, $request->integer('monthly_price_override_pence'))
            : null;
        $tenant->forceFill(['monthly_price_override_pence' => $pence])->save();
        $this->audit($tenant, 'billing.price_override', ['pence' => $pence]);

        return back()->with('toast', $pence === null ? 'Price override cleared.' : 'Founding price set.');
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
