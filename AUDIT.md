# Appoint Manager — pre-launch audit

Read of the whole tree on 2026-08-22 against `DECISIONS.md`, `DESIGN.md`, `LAUNCH.md`.
Test suite confirmed green: **97 passed, 457 assertions, 4.6s** (sqlite `:memory:`, `QUEUE_CONNECTION=sync`).

Nothing in this repository was changed.

Two claims in the audit brief were verified by executing them rather than by reading:

- `Rule::exists(Model::class, 'id')` **does** bypass the tenant global scope (probe: cross-tenant ids passed validation).
- `bookings.staff_id → users.id ON DELETE CASCADE` **is** emitted (probe: `PRAGMA foreign_key_list(bookings)`).

One thing the brief asked about is genuinely fixed: the PaymentIntent is created **after** the
transaction commits (`BookingService.php:118-120`), not inside it. That specific flag is closed.
The lock is still held across the network for other reasons — see C4.

---

## Critical

Data leak, money loss, or security hole. None of these should survive contact with a real customer.

### C1 — `FakeStripeGateway` activates silently in production, and its signature check is a string literal

`app/Providers/AppServiceProvider.php:34-40`
```php
$this->app->singleton(StripeGateway::class, function () {
    if ($this->app->environment('testing') || ! config('services.stripe.secret')) {
        return new FakeStripeGateway;   // <- any empty/missing STRIPE_SECRET
    }
    return new StripeConnectGateway;
});
```
`app/Services/Stripe/FakeStripeGateway.php:84-89`
```php
public function constructEvent(string $payload, string $signature): array
{
    if ($signature !== 't=1,v1=test') {
        throw new RuntimeException('Invalid Stripe signature.', 400);
    }
```

`.env.example` ships `STRIPE_SECRET=` empty. `config:cache` run before the env var is populated on
Forge produces the same state. There is no boot-time assertion that a real gateway is bound.

**What goes wrong in practice.** With `STRIPE_SECRET` unset in production, an unauthenticated
attacker sends:

```
POST /stripe/webhook
Stripe-Signature: t=1,v1=test
{"id":"evt_x","type":"payment_intent.succeeded","data":{"object":{"metadata":{"booking_id":"41"}}}}
```

and booking 41 — in any tenant — flips to `confirmed` / `deposit_status=paid` with no money taken.
The same route accepts `account.updated` to mark any tenant `stripe_onboarding_complete`. Separately,
every real deposit silently returns a fake `pi_fake_1` client secret and no card is ever charged.
`/stripe/webhook` is CSRF-exempt (`bootstrap/app.php:38-42`) and unthrottled.

**Fix.** In `AppServiceProvider::register()`, bind `FakeStripeGateway` on `environment('testing')`
**only**; in any other environment with a missing secret, throw at bind time. Same for
`BillingGateway` (`:42-48`). Delete the literal-signature branch from `FakeStripeGateway` and have
tests bind the fake explicitly. Add a `/health` check asserting `StripeGateway` resolves to
`StripeConnectGateway` outside testing.

---

### C2 — The Connect webhook trusts attacker-controllable `metadata.booking_id` with no tenant or account check

`app/Services/Stripe/StripeEventProcessor.php:28-56`
```php
$bookingId = (int) ($object['metadata']['booking_id'] ?? 0);
$intentId  = (string) ($object['id'] ?? '');

$booking = Booking::withoutGlobalScopes()
    ->when($bookingId > 0, fn ($q) => $q->whereKey($bookingId))
    ->when($bookingId === 0 && $intentId !== '', fn ($q) => $q->where('stripe_payment_intent_id', $intentId))
    ->first();
...
$booking->forceFill(['status' => Confirmed, 'deposit_status' => Paid, ...])->save();
```

Three separate defects in eight lines:

1. **No tenant / connected-account binding.** This is a Connect endpoint: it receives events from
   *every* connected account. A connected salon controls its own Stripe account and can create a 1p
   PaymentIntent with `metadata.booking_id = <a competitor's booking id>`. The resulting genuine,
   correctly-signed `payment_intent.succeeded` marks **another tenant's booking** paid. The event's
   `account` field is never read, and `$booking->tenant_id` is never compared to anything.
2. **No amount check.** `deposit_at_booking` is never compared to `object.amount_received`. A 1p
   intent confirms a £50 deposit.
3. **Unconstrained fallback.** If `$bookingId === 0` *and* `$intentId === ''`, neither `when()` fires
   and `->first()` returns an arbitrary booking — the lowest id in the table, in whatever tenant that
   happens to be — and confirms it. A malformed event is enough.

`chargeRefunded()` (`:58-74`) has the same shape: matched by intent id across all tenants with no
tenant assertion.

**Fix.** Require `$bookingId > 0`, load the booking, and reject unless
`Tenant::find($booking->tenant_id)->stripe_account_id === $event['account']`. Reject unless
`$object['amount_received'] >= $booking->deposit_at_booking->amount` and the currency matches.
Delete the unconstrained branch — return early instead. Store the event `account` on `stripe_events`
so this is auditable after the fact.

---

### C3 — The Stripe refund runs inside the DB transaction, before code that can throw

`app/Services/Booking/BookingService.php:143-179`
```php
return DB::transaction(function () use (...) {
    $booking = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
    ...
    if ($this->outsideRefundWindow($tenant, $booking)) {
        $this->stripe->refundPaymentIntent(...);        // :152 — network call, irreversible
        $booking->deposit_status = DepositStatus::Refunded;
    }
    $booking->forceFill([...])->save();
    AvailabilityCache::bust($tenant->id);
    $this->notifier->bookingCancelled($booking, $refundStatus);   // :169 — sends mail + SMS, can throw
    if ($offerWaitlist) { $this->waitlist->offerForBooking($booking); }  // :172 — can throw
});
```

**What goes wrong in practice.** The refund succeeds at Stripe. Then Twilio is down, or the customer
row has a phone number Twilio rejects, or the SMTP connection times out — `Notifier` does not catch
anything (`Notifier.php:144`, `:160`; `TwilioSmsGateway.php:27` has no try/catch). The exception
unwinds the transaction. The money is gone from the salon's Stripe balance, `deposit_status` is
still `Paid`, `status` is still `confirmed`, and the slot is still blocked. Nobody is told. The salon
discovers it in their Stripe dashboard weeks later.

Note this also means the Stripe refund is retried on every subsequent cancel attempt, because the DB
never recorded that it happened.

**Fix.** Take the refund out of the transaction. Commit the cancellation first (status, cancelled_at,
`deposit_status = refund_pending`), then refund outside the transaction with an idempotency key, then
record the outcome in a second write. Notifications must be queued jobs, not synchronous calls in a
transaction — see C4.

---

### C4 — Booking creation sends email and SMS synchronously while holding `lockForUpdate()`

`app/Services/Booking/BookingService.php:82-116`
```php
$booking = DB::transaction(function () use (...) {
    $this->lockStaffWindow($tenant, $staff, $startsAt, $endsAt, $service->buffer_minutes);  // FOR UPDATE
    ...
    $booking->save();
    AvailabilityCache::bust($tenant->id);
    if ($booking->status === BookingStatus::Confirmed) {
        $this->notifier->bookingConfirmed($booking);    // :112
    }
    return $booking;
});
```
`app/Services/Notifications/Notifier.php:142-162`
```php
Mail::to($customer->email)->send($mail);     // :144 — synchronous SMTP
...
$id = $this->sms->send($customer->phone, $body);   // :160 — synchronous Twilio HTTP
```

`bookingConfirmed()` sends up to two emails and one SMS, all blocking, all inside the open
transaction. None of the mailables implement `ShouldQueue`.

**What goes wrong in practice.** Two things, both bad:

- **Locks held across third-party latency.** `lockStaffWindow()` holds InnoDB row/gap locks on that
  staff member's booking range for the duration of an SMTP handshake plus a Twilio API round trip —
  routinely 300ms–2s, unbounded if the provider is slow. Every concurrent booking attempt for that
  staff member queues behind it or hits `innodb_lock_wait_timeout` (default 50s) and 500s. This is
  the exact thing `DECISIONS.md` claims is avoided: *"the row lock is never held across the
  network."* That statement is true for the PaymentIntent and false for mail and SMS.
- **Mail outage destroys the booking.** Twilio 503 or SMTP timeout → exception → transaction rolls
  back → the customer's booking never existed. The public form shows the generic *"We couldn't finish
  this booking"* (`BookingIsland.vue:322`) for what was a working slot.

`bookingConfirmed()` is also invoked from `StripeEventProcessor::paymentSucceeded()` (`:55`) —
outside a transaction there, but a Twilio failure then fails the whole job, and the job has already
written `status = confirmed`, so on retry `:39` short-circuits and **the confirmation email is never
sent** for a booking the customer has paid for.

**Fix.** Make every mailable `ShouldQueue`. Wrap `SmsGateway::send()` in a queued job. Move the whole
`notifier->bookingConfirmed()` call out of the transaction — dispatch after commit. Set
`'after_commit' => true` on the queue connections (see M6) so dispatches inside transactions behave.

---

### C5 — Stripe failing at checkout is invisible to the customer

`app/Services/Booking/BookingService.php:125-134`
```php
private function attachPaymentIntent(Tenant $tenant, Booking $booking): void
{
    try {
        $intent = $this->stripe->createPaymentIntent($tenant, $booking);
        ...
    } catch (Throwable $exception) {
        report($exception);          // logged, and that is all
    }
}
```
`app/Http/Controllers/PublicBookingController.php:201-214` then returns HTTP **201** with
`"payment": null`, and:

`resources/js/Pages/Public/BookingIsland.vue:302-313`
```js
if (data.payment?.client_secret) { ... step.value = 'pay'; return; }
paymentState.value = data.booking.status === 'confirmed' ? 'confirmed' : 'pending';
step.value = 'done';
```
`resources/js/Pages/Public/BookingIsland.vue:512-514`
```
"Almost there"
"We’re holding the slot while payment confirms."
```

**What goes wrong in practice.** Stripe has a partial outage. The customer picks a slot, fills in
their details, presses confirm, and is told *"Almost there — we're holding the slot while payment
confirms."* There is no card form and there never will be. Fifteen minutes later
`bookings:release-expired` cancels the booking and texts them *"cancelled"* (see H9). The salon sees
nothing. The customer books elsewhere.

This is the answer to "Stripe is down during checkout: does it fail safe, loud, or silent?" — it
fails **silent**, and the copy actively misleads.

**Fix.** Let `createPaymentIntent` failure propagate. Cancel the pending booking and return 503 with
"We couldn't reach payments — nothing has been charged, please try again in a minute." The `done`
step must never be reachable for a `pending` booking that has no client secret.

---

### C6 — `/stripe/webhook` returns 200 and drops the event when the DB write fails

`app/Http/Controllers/StripeWebhookController.php:31-47`
```php
try {
    $row = StripeEvent::query()->firstOrCreate(['event_id' => $event['id']], [...]);
} catch (Throwable) {
    return response('ok', 200);      // :40 — Stripe will never retry this event
}
```

**What goes wrong in practice.** A brief MySQL blip, a deadlock, a full disk, a connection-pool
exhaustion during a traffic spike. The `payment_intent.succeeded` for a real £15 deposit is
acknowledged with 200 and thrown away. Stripe's retry schedule (which is the entire safety net for
webhook delivery) is disarmed. The customer is charged, the booking stays `pending`, and
`bookings:release-expired` cancels it 15 minutes later. The money is in the salon's Stripe balance
against a booking that no longer exists.

The bare `catch (Throwable)` also does not write a `WebhookFailure` row, so it does not even appear
in `/admin/failures`. The billing webhook (`BillingWebhookController.php:40-48`) at least logs the
failure, though it also returns 200.

**Fix.** Return **500** on a storage failure so Stripe retries, and write a `WebhookFailure` row
first. The idempotency guard is the `stripe_events.event_id` unique index — catch only the duplicate-key
case and return 200 for that specific exception.

---

### C7 — Stored XSS on the public booking page via tenant-controlled JSON-LD

`resources/views/public-shell.blade.php:13-26, 35`
```php
$jsonLd = ['@type' => 'LocalBusiness', 'name' => $tenant->name, 'telephone' => $tenant->phone,
           'address' => [... $tenant->address_line_1, $tenant->city, $tenant->postcode ...]];
```
```blade
<script type="application/ld+json">{!! json_encode($jsonLd, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !!}</script>
```

No `JSON_HEX_TAG`, plus `JSON_UNESCAPED_SLASHES`, so `</script>` passes through byte-for-byte and
terminates the block. Line 47 of the same file gets this right (`JSON_HEX_TAG | JSON_HEX_AMP`) —
line 35 was missed.

The inputs are `UpdateSettingsRequest` fields validated only as `string|max:255`
(`app/Http/Requests/Settings/UpdateSettingsRequest.php:20-28`), editable by **any** tenant user
(see M5). `SecurityHeaders.php:24` sets `script-src 'self' 'unsafe-inline' ...`, so CSP blocks
nothing here.

**What goes wrong in practice.** A tenant sets their business name to
`X</script><script>/* skim */</script>`. Their public booking page — the same page that mounts the
Stripe card element at the `pay` step (`BookingIsland.vue:87-104`) — now runs attacker JS in the
browser of every customer entering card details. Cross-tenant it is a lateral move, not a jump; for
the salon's *customers* it is a card-skimming vector on a page they were told to trust. The
`/preview/{token}` route (`PreviewBookingController.php:18`) renders the same shell.

**Fix.** Add `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` to line 35 and drop
`JSON_UNESCAPED_SLASHES`. Separately, remove `'unsafe-inline'` from `script-src` — with Inertia and
the islands that means nonces, but the CSP is currently decorative.

---

### C8 — "Delete your account" hard-deletes every booking that staff member owns

`app/Http/Controllers/ProfileController.php:46-62`
```php
$request->validate(['password' => ['required', 'current_password']]);
$user = $request->user();
Auth::logout();
$user->delete();          // :56 — hard delete; User has no SoftDeletes
```
Verified FK (probe against the real schema):
```
staff_id -> users.id  ON DELETE CASCADE
```

**What goes wrong in practice.** A single-operator dog groomer — the exact first customer this is
being launched for — is the only `users` row in their tenant. They tinker in Profile, type their
password, click Delete. MySQL cascades: **every booking they are the staff on is destroyed**, past
and future, paid and unpaid, along with their `availability_rules` and `time_off`. The `tenants`
row, the `customers` and the `services` all survive, so the account looks intact and the diary is
simply empty. Deposits already taken now point at nothing. There is no soft delete and no backup
older than the nightly dump.

There is also no guard that this is the last owner, and `StaffPolicy::delete()` returns `false`
(`app/Policies/StaffPolicy.php:29-32`) — so deleting a colleague is forbidden while deleting
yourself, with far worse consequences, is one form away.

**Fix.** Change `bookings.staff_id` to `restrictOnDelete()` (or `nullOnDelete()` with a
`deactivated_staff_name` snapshot). Add `SoftDeletes` to `User`. Block self-deletion for the last
active owner in a tenant. Realistically: replace "delete account" with "deactivate" entirely.

---

### C9 — The tenant scope is fail-**open** in queue workers and scheduled commands

`app/Models/Scopes/TenantScope.php:31-42`
```php
private function shouldFailClosed(): bool
{
    if (! $this->failClosed) { return false; }
    if (app()->runningInConsole() && ! app()->runningUnitTests()) {
        return false;                    // <- queue:work and every artisan command
    }
    return true;
}
```

`runningInConsole()` is true for `queue:work`, `schedule:run` and every console command. So in the
entire background half of the application, `Booking::query()`, `Customer::query()`,
`Message::query()` etc. with no tenant context return **rows from every tenant**, silently, with no
error.

The comment on `BelongsToTenant.php:46-48` says this is deliberate so seeders can pass an explicit
`tenant_id`. That is a reasonable goal achieved by the most dangerous possible mechanism: it disarms
the safety net for all background code in exchange for convenience in two seeder files.

**Current exposure.** I traced every background call site. All of them do happen to pass an explicit
`tenant_id` or key off a globally-unique id, so I found **no live leak today**. But:

- `SendBookingReminder.php:19`, `ReleaseExpiredPendingBookings.php:20`,
  `SendDailyAgendas.php:30` all use `withoutGlobalScopes()` anyway — the fail-open is not even
  buying them anything.
- `ProcessStripeEvent`/`ProcessBillingEvent` set no tenant context; `StripeEventProcessor` sets one
  only *after* the unscoped booking lookup (`:43-47`).
- The test suite runs `QUEUE_CONNECTION=sync` (`phpunit.xml`), so jobs execute inside the HTTP
  request where the context *is* set. **No test can ever catch this class of bug.** The one test
  that asserts fail-closed (`TenantIsolationTest.php:36-40`) passes precisely because
  `runningUnitTests()` is true.

This is the "silently returns unscoped rows" nightmare, armed and waiting for the next `Booking::query()`
someone writes in a job.

**Fix.** Delete `shouldFailClosed()`'s console exemption — always fail closed. Give seeders an
explicit `TenantContext::runFor($tenant, fn () => ...)` helper and use it. Add a test that boots a
real queue worker (`QUEUE_CONNECTION=database` in one test) and asserts a job with no context reads
zero rows.

---

### C10 — The public booking page is a customer PII lookup service

`app/Http/Controllers/PublicBookingController.php:120-160`
```php
public function customerMatch(Request $request): JsonResponse
{
    $tenant = $this->tenant($request);
    $email = trim((string) $request->query('email'));
    $phone = trim((string) $request->query('phone'));
    $customer = Customer::query()->where('tenant_id', $tenant->id)
        ->where(function ($q) use ($email, $phone) {
            if ($email !== '') { $q->where('email', $email); }
            if ($phone !== '') { $q->orWhere('phone', $phone); }
        })->with('subjects')->first();
    ...
    return response()->json(['customer' => ['id','name','email','phone'], 'subjects' => [...]]);
}
```

Unauthenticated. `GET /book/{slug}/customer-match?email=someone@example.com` returns that person's
**name, email address, phone number, and the names and attributes of their pets**. Rate limited at
60/min per IP (`AppServiceProvider.php:68-70`), which permits 86,400 lookups per day per IP.

The same controller then does this on booking (`:275-307`):
```php
$customer->fill(['name' => $name, 'phone' => $phone === '' ? ... : PhoneNumber::toE164(...)]);
$customer->save();     // :304
```
so an unauthenticated visitor who supplies a known email **overwrites that customer's stored name and
phone number** in the salon's CRM, and their new booking is attached to the existing customer record.

**What goes wrong in practice.** Anyone with a target's email address learns their phone number and
their dog's name and breed from a public URL — a reportable personal data breach under UK GDPR, on
data the salon is the controller for. And a bored competitor can walk a leaked email list through the
endpoint to enumerate a salon's client base, or corrupt the phone numbers so reminders stop
arriving.

The front end makes it worse: `BookingIsland.vue:240-245` calls `matchCustomer()` on a watcher with
no debounce, so it also fires ~20 requests while a legitimate customer types their email, and
auto-fills the matched name into the form (`:232-234`).

**Fix.** `customerMatch` must not return PII for an unverified requester. Either delete it and let
returning customers use the `/b/{token}` link they already have, or return only
`{"known": true, "subjects": [{"id", "name"}]}` behind a short-lived emailed code. Never
`orWhere('phone')` — that widens the match. On booking, match on email but **never** overwrite
`name`/`phone` on an existing customer from unauthenticated input; write the supplied values onto the
booking instead and let the salon reconcile.

---

## High

Will cause a visible failure or corrupt data under normal use.

### H1 — Registration and password reset have no rate limiting

`routes/auth.php:18, 29-30, 35-36`
```php
Route::post('register', [RegisteredUserController::class, 'store']);              // no throttle
Route::post('forgot-password', [PasswordResetLinkController::class, 'store']);    // no throttle
Route::post('reset-password', [NewPasswordController::class, 'store']);           // no throttle
```
Only `login` is throttled (`:23-24`). `LAUNCH.md` ticks *"Rate limits: public booking, availability,
login"* — that list is accurate and incomplete.

**In practice.** `POST /forgot-password` in a loop against a known salon owner's address sends
unbounded reset emails: an inbox flood for them and a spam-complaint-driven sender-reputation
collapse for you, on the same mail domain that sends booking confirmations. `POST /register` in a
loop creates unbounded `tenants` rows, each with a 30-day trial, each with a unique slug (and each
`TenantSlug::generate` call is an O(n) `exists()` loop, so this degrades quadratically).

**Fix.** `throttle:5,1` keyed by email+IP on forgot-password and reset-password;
`throttle:3,60` keyed by IP on register.

### H2 — `Rule::exists()` bypasses the tenant scope on four form requests

Verified by probe: cross-tenant ids pass validation.

- `app/Http/Requests/Services/StoreServiceRequest.php:32` — `staff_ids.*` → `Rule::exists(User::class,'id')`
- `app/Http/Requests/Services/UpdateServiceRequest.php:31` — same
- `app/Http/Requests/TimeOff/StoreTimeOffRequest.php:24` — `user_id` → `Rule::exists(User::class,'id')`
- `app/Http/Requests/Services/ReorderServicesRequest.php:24` — `ids.*` → `Rule::exists(Service::class,'id')`

`ServiceController::store/update` then calls `$service->staff()->sync($request->input('staff_ids'))`
(`:56`, `:70`) directly onto the `service_user` pivot, which has **no tenant column and no tenant
check**.

**In practice.** Tenant A posts `staff_ids: [<a user id belonging to tenant B>]`. The pivot row is
written. `ServiceController::index` eager-loads `with('staff')` (`:23`) and
`ServicePayload::toArray()` returns `staff_ids` (`ServicePayload.php:26`) — and the Services screen
renders tenant B's staff member. `AvailabilityEngine::staffWhoCanPerform()` filters on `tenant_id`
(`:146`) so bookings are unaffected, but the *existence and identity of another tenant's staff* has
leaked. `StoreTimeOffRequest` is the same shape: tenant A creates a `time_off` row (stamped with
tenant A's `tenant_id` by the trait) pointing at tenant B's user, and `TimeOffController::index`
does `with('user')` (`:22`) and renders `$entry->user?->name` (`:28`) — tenant B's staff name on
tenant A's screen.

**Fix.** Replace every `Rule::exists(Model::class, 'id')` on a tenant-owned model with
`Rule::exists($table, 'id')->where('tenant_id', current_tenant_id())`. Add a `tenant_id` column to
`service_user` with a composite FK, or validate the sync payload against the tenant before syncing.

### H3 — GDPR delete destroys paid bookings with no refund and no warning

`app/Http/Controllers/CustomerPrivacyController.php:34-47`
```php
DB::transaction(function () use ($customer) {
    Message::query()->where('customer_id', $customer->id)->delete();
    WaitlistEntry::query()->where('customer_id', $customer->id)->delete();
    Booking::query()->where('customer_id', $customer->id)->delete();   // :41 hard delete
    Subject::query()->where('customer_id', $customer->id)->delete();
    $customer->delete();
});
```
`Booking` has no `SoftDeletes`, so `:41` is a hard delete of every booking including *future,
confirmed, deposit-paid* ones.

**In practice.** A customer emails "delete my data". The owner clicks Delete on the customer screen.
Three future bookings with £15 deposits each vanish. The slots silently free up with nobody notified.
The deposits sit in Stripe with no local record. Revenue reporting for prior months changes
retroactively. There is no confirmation step listing what will be destroyed and no refund.

**Fix.** Refuse the delete while future non-cancelled bookings exist, and say so ("3 upcoming
appointments — cancel and refund them first"). For past bookings, anonymise rather than delete:
null the customer FK and keep `starts_at`/`price_at_booking` so the books still balance. That is
what GDPR erasure actually requires.

### H4 — Soft-deleting a service silently blanks the service on every existing booking

`app/Http/Controllers/ServiceController.php:77-84` calls `$service->delete()` (soft). Every read path
resolves the service through the relation, which applies `SoftDeletingScope`:

`app/Support/BookingPayload.php:25` → `'service_name' => $booking->service?->name` → `null`.

**In practice.** A salon retires "Puppy Trim" in January. Every past and future booking for it now
renders with a blank service name in the diary, the bookings list, the customer detail screen, and
every email built from `BookingPayload`. `DashboardController` and reporting still count them, so the
numbers and the labels disagree. There is no warning at delete time that N future bookings exist.

**Fix.** Add `->withTrashed()` to the `service()` relation on `Booking` (or eager-load with
`withTrashed`). Warn at delete time when future bookings reference the service; offer archive
(`is_active = false`) as the default action. Same review needed for `staff` once `User` gains soft
deletes (C8).

### H5 — Rescheduling does not reschedule the reminder

`app/Services/Notifications/Notifier.php:112-119` — `scheduleReminder()` is called **only** from
`bookingConfirmed()` (`:48`). `bookingRescheduled()` (`:65-74`) does not touch the reminder, and
`BookingService::reschedule()` (`:182-214`) does not clear `reminder_cancelled_at` or dispatch a new
job.

The delayed `SendBookingReminder` job holds only `$bookingId` (`SendBookingReminder.php:15`) and
re-reads `starts_at` at run time (`Notifier.php:79`).

**In practice.** Booking is Friday 10:00; reminder job is queued for Wednesday 10:00. The customer
moves it to the following Tuesday 14:00. On Wednesday the original job fires and texts *"reminder
10 Mar 14:00"* — a reminder for an appointment six days away, and then no reminder at all 48 hours
before the real one. Move it *earlier* instead and the reminder arrives after the appointment has
already happened. Every reschedule produces a wrong-time reminder.

**Fix.** In `reschedule()`, set `reminder_cancelled_at = now()` on the old row before saving, then
clear it and dispatch a fresh `SendBookingReminder` with the new delay. Better: give the job a
`scheduled_for` property and have it no-op if `starts_at` has moved.

### H6 — The booking lock is untested where it matters and can deadlock rather than serialise

`app/Services/Booking/BookingService.php:318-328`
```php
Booking::withoutGlobalScopes()
    ->where('tenant_id', $tenant->id)->where('staff_id', $staff->id)
    ->where('status', '!=', BookingStatus::Cancelled->value)
    ->where('starts_at', '<', $endsAt->addMinutes($buffer))
    ->where('ends_at', '>', $startsAt->subMinutes($buffer))
    ->lockForUpdate()->get();
```

For the case that matters — an **empty** slot, where the SELECT matches zero rows — this acquires
InnoDB *gap locks* over the range on `(tenant_id, staff_id, starts_at)`. Gap locks are purely
inhibitive: two transactions can hold gap locks on the same gap simultaneously. Each then attempts
its INSERT, whose insert-intention lock conflicts with the other's gap lock. The result is not clean
serialisation — it is a **deadlock (MySQL 1213)**, and nothing catches it:
`PublicBookingController::store` catches only `SlotUnavailableException` and `InvalidArgumentException`
(`:193-197`), so a `QueryException` becomes a 500 and the customer sees *"We couldn't finish this
booking."*

`DECISIONS.md` is candid that `lockForUpdate()` is a no-op on sqlite, and the tests confirm it:

- `BookingConcurrencyTest.php:72-98` injects a synthetic insert via a **production test hook**
  (`BookingService::withAfterLock`, `:51-56`) *inside the same transaction*. It proves
  `assertSlotOpen()` re-reads after the lock. It does not exercise a lock.
- `BookingConcurrencyTest.php:100-131` fires two **sequential** HTTP POSTs.
- `WaitlistTest.php:65-98` — "two simultaneous claims" — is also two sequential POSTs.

So there is currently **no evidence at all** that the production locking strategy prevents double
booking on MySQL, which is the only database it will ever run on. The mechanism is plausible; it is
unverified.

**Fix.** Add a unique constraint that makes double booking impossible regardless of locking — e.g.
`UNIQUE (staff_id, starts_at)` filtered to non-cancelled rows (a generated column or a status-aware
key), and catch the duplicate-key violation as `SlotUnavailableException`. Catch `QueryException`
with SQLSTATE 40001 and retry once. Add a MySQL-backed test that forks two real processes booking the
same slot and asserts exactly one row.

### H7 — `/twilio/status` accepts unauthenticated writes with no signature verification

`app/Http/Controllers/TwilioStatusController.php:12-34`
```php
$sid = (string) $request->input('MessageSid');
$status = strtolower((string) $request->input('MessageStatus'));
$message = Message::withoutGlobalScopes()->where('provider_id', $sid)->first();
$message->forceFill(['status' => $mapped])->save();
```
No `X-Twilio-Signature` check (Twilio's SDK ships `RequestValidator` and it is not used), CSRF-exempt
(`bootstrap/app.php:38-42`), no rate limit, cross-tenant by design.

**In practice.** Anyone who can guess or observe a Twilio `SM...` SID can flip delivery status on
another tenant's message. The blast radius is limited to the `messages` table — but this is the
salon's only evidence of whether a reminder reached the customer, which is exactly what gets argued
about after a no-show. It is also an unauthenticated write endpoint that will be found by scanners.

**Fix.** Validate `X-Twilio-Signature` with `Twilio\Security\RequestValidator` against
`TWILIO_TOKEN` and the full request URL. Add `throttle`. Return 403 on failure.

### H8 — Authorization is tenant-membership only; there are no roles

Every policy in `app/Policies/` reduces to the same check:
```php
return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
```
`UserRole` exists (`app/Enums/UserRole.php`) and `User::isOwner()` exists (`User.php:66-69`), but it
is consulted in exactly **one** place: `CustomerPolicy::delete` (`:23-26`).

**In practice.** A part-time Saturday assistant, invited via `StaffController::store` with
`role = staff`, can: change every service price to £0 (`UpdateServiceRequest` has no role check),
delete services, cancel any booking and trigger refunds (`BookingController::destroy` →
`authorize('update')`), read and export every customer's full record
(`CustomerPrivacyController::export`), bulk-import bookings, change the business timezone, and
deactivate the owner's account (`StaffPolicy::update` only checks same-tenant; the self-deactivation
guard in `UpdateStaffRequest::withValidator` protects *themselves*, not the owner).

`StaffController::update` also has no `$this->authorize()` call in the controller body (`:53-58`) —
it relies entirely on `UpdateStaffRequest::authorize()`, which is fine today but is the sort of thing
that gets dropped in a refactor.

**Fix.** Add `owner`-only gates to: service create/update/delete/reorder, staff create/update,
settings update, imports, billing, customer export/delete, and booking cancel-with-refund. This is a
day of work and it is the difference between "trusted co-owner" and "anyone with a login".

### H9 — Abandoned checkouts blast the waitlist and text the customer a cancellation

`app/Console/Commands/ReleaseExpiredPendingBookings.php:20-24`
```php
->each(fn (Booking $booking) => $bookings->cancel($booking, 'checkout_expired'));
```
`cancel()`'s third parameter `$offerWaitlist` defaults to **true** (`BookingService.php:136`), and
`cancel()` unconditionally calls `$this->notifier->bookingCancelled(...)` (`:169`).

**In practice.** A customer opens the booking page, gets to the card step, and closes the tab —
which is a large fraction of checkout starts. Fifteen minutes later, every waitlist customer matching
that service and time window (up to `waitlist_offer_batch = 5`) gets an SMS: *"a slot is free.
Claim: ..."* for a slot that was never actually booked and has been free all along. And the customer
who abandoned gets an email **and** an SMS telling them their booking is cancelled — for a booking
they never completed and were never told they had.

At Twilio's UK rate this is real money per abandonment, and it trains waitlist customers to ignore
the notifications that matter.

**Fix.** Pass `offerWaitlist: false` from `ReleaseExpiredPendingBookings`. Add a `notify: false`
parameter to `cancel()` and use it for `checkout_expired` — an expired hold should release the slot
in silence.

### H10 — CSV import: unvalidated upload, unbounded memory, all-or-nothing transaction, uncaught phone parse

`app/Http/Controllers/ImportController.php:45-52`
```php
if ($request->hasFile('file')) {
    return (string) file_get_contents($request->file('file')->getRealPath());
}
```
No `validate()` at all — no mime check, no size cap, no row cap. The entire file is read into memory
and the entire import runs in one `DB::transaction` inside an HTTP request
(`CustomerCsvImporter.php:26`, `BookingCsvImporter.php:33`).

`app/Services/Onboarding/CustomerCsvImporter.php:72`
```php
'phone' => $phone === '' ? null : PhoneNumber::toE164($phone, $tenant->country ?? 'GB'),
```
`PhoneNumber::toE164` throws `InvalidArgumentException` (`PhoneNumber.php:25, 29`) and nothing
catches it. **`preview()` skips this line entirely** (`:59-63` returns before the phone is touched).

**In practice.** The onboarding flow is: paste your CSV, review the dry run, click Import. The dry
run says "48 rows OK". Row 31 has `phone = "ask her"`. Commit throws an uncaught
`InvalidArgumentException` → 500 error page → the transaction rolls back → **zero rows imported**,
with no indication of which row was at fault. The new salon's first experience of the product is an
error page. Meanwhile a 40MB export from their old system OOMs PHP before it gets that far.

**Fix.** `$request->validate(['file' => ['required','file','mimes:csv,txt','max:5120']])`. Validate
the phone in `preview()` on the same code path as `import()` so the dry run is truthful. Wrap each
row in its own try/catch and report per-row failures instead of aborting; commit per row (or in
chunks), not one transaction for the whole file. Cap at e.g. 5,000 rows and queue anything larger.
Also add an `authorize()` — the import currently only checks `hasAdminWriteAccess()` (`:24`, `:36`).

### H11 — Invalid price input silently becomes £0.00

`resources/js/lib/money.ts:8-19`
```js
const match = cleaned.match(/^(\d+)(?:\.(\d{0,2}))?$/);
if (!match) { return 0; }        // :12-13
```

**In practice.** The owner types `£45`, or `45,00`, or `1,200`, or pastes a value with a trailing
space — none match the regex — and the field silently submits **0**. `StoreServiceRequest` validates
`'price' => ['required','integer','min:0']`, and 0 is a valid integer ≥ 0, so it saves cleanly with a
success toast. The service is now free. `deposit_amount` has `lte:price`, so the deposit is forced to
0 too and the service stops taking deposits. Nobody finds out until the money doesn't arrive.

**Fix.** Return `null` on no-match and surface a field error; do not coerce to zero. Strip currency
symbols, spaces and thousands separators before matching. Consider requiring a non-zero price at the
form-request level with an explicit "free" checkbox for the genuine £0 case.

### H12 — Nothing detects a dead queue worker

`app/Http/Controllers/HealthController.php:54-61`
```php
private function queue(): bool
{
    try { return config('queue.default') !== null; }   // always true
    catch (Throwable) { return false; }
}
```
This reads a config value that is never null. It is a tautology reported to the uptime monitor as
`"queue": true`.

**In practice.** The Forge worker dies (OOM, deploy race, `horizon:terminate` on a Horizon that isn't
installed — see M12). `/health` keeps returning `{"ok": true, "checks": {"queue": true}}`. Stripe
webhooks are accepted and queued but never processed, so paid bookings sit `pending` and get
auto-cancelled by the scheduler. Reminders never send. This is the "queue worker dies" scenario and
the answer is: **fail silent**, with a green health check.

**Fix.** Check queue depth and oldest-job age against a threshold (`jobs` table `created_at` or
`Queue::size()`), and count `failed_jobs` in the last hour. Alert on both.

### H13 — The billing page makes three uncached, unguarded Stripe API calls

`app/Http/Controllers/BillingController.php:28-30`
```php
'next_charge'    => $billing->nextInvoiceAt($tenant),      // subscriptions->retrieve
'payment_method' => $billing->paymentMethodLabel($tenant), // customers->retrieve
'invoices'       => $billing->invoices($tenant),           // invoices->all(limit: 24)
```
Three synchronous round trips on every page load, no caching, no try/catch anywhere in
`StripeBillingGateway`.

**In practice.** Stripe degrades and `/billing` — the page the salon goes to when they are worried
about their subscription — throws an unhandled exception and 500s. On a normal day it is a 600ms+
page. `StripeBillingGateway::invoices()` also formats money with
`number_format(($invoice->amount_paid ?? 0) / 100, 2)` (`:84`) — float division on money, and the
only place in the PHP layer that does it.

**Fix.** Wrap each call, degrade to "unavailable" rather than 500. Cache per tenant for a few
minutes. Use `intdiv`/`Money` for the invoice amount.

### H14 — `users.email` is globally unique, not unique per tenant

`database/migrations/0001_01_01_000000_create_users_table.php` — `$table->string('email')->unique();`
Enforced again at `StoreStaffRequest.php:24` and `RegisterRequest.php:25` via `Rule::unique('users','email')`.

**In practice.** A groomer who works Saturdays at two salons on the platform cannot be added to the
second one — the invite fails with "email has already been taken", naming an account in a tenant the
salon cannot see. It is also an account-existence oracle: registration tells an anonymous visitor
whether a given email already has an account anywhere on the platform. For a product whose whole
premise is many small independent businesses, this will be hit early.

**Fix.** `unique(['tenant_id','email'])` and scope authentication lookups accordingly (which means a
tenant-aware login, or accepting the constraint deliberately and documenting it). Not a quick change —
decide before the first customer, not after.

---

## Medium

Will cause problems as usage grows.

**M1 — Availability cache is only busted on booking writes.** `AvailabilityCache::bust()` is called
from `BookingService::create/cancel/reschedule` only. Changing opening hours
(`AvailabilityController::sync`), booking time off (`TimeOffController::store/destroy`), or editing a
service's duration/buffer (`ServiceController::update`) leaves the public cache stale.
`availability_cache_ttl` is 60s (`config/booking.php`), so the window is short and
`assertSlotOpen()` rejects a stale booking with a clean 409 — it fails safe. Still: bust on those
writes.

**M2 — The `booking-manage` rate limiter provides no protection against token enumeration.**
`AppServiceProvider.php:76-78` keys on `$request->ip().'|'.$request->route('token')`. An enumerator
uses a *different* token every request, so every request gets a fresh bucket — the limiter is
structurally incapable of limiting the attack it exists for. The tokens themselves are `Str::uuid()`
(v4, 122 bits of entropy — `Booking.php:45-49`, `SlotOffer.php:32-36`), so brute force is infeasible
and this is not currently exploitable. Key on IP alone.

**M3 — Impersonation is unrestricted and thinly audited.** `SuperAdminController::impersonate`
(`:84-102`) is correctly gated by `super-admin` middleware and writes an `impersonate.start` audit
row — good. But the resulting session has full owner powers: the super admin can cancel bookings,
issue refunds and change prices, and every one of those writes is attributed to the salon owner with
no audit trail. `ImpersonationController::stop` (`:11-29`) loads the stored `impersonator_id` and
calls `auth()->login($actor)` **without re-checking `is_super_admin`** — if the flag were revoked
mid-session, the restore still succeeds. Make impersonated sessions read-only (or tag every write
with the impersonator), and re-verify `is_super_admin` in `stop()`.

**M4 — Super admin tenant list is N+1 and unpaginated.** `SuperAdminController::index:24-49` loads
every tenant and runs a `Booking::withoutGlobalScopes()->count()` per row (`:39-42`), recomputing
`now()->startOfMonth()` inside the loop (`:28`). At 100 tenants that is 101 queries; at 1,000 it is a
timeout. Replace with one grouped aggregate and paginate.

**M5 — Any tenant user can change the business timezone.** `UpdateSettingsRequest::authorize()`
(`:10-13`) checks only tenant membership; `timezone` is in `rules()` (`:22`). Changing it
reinterprets every `availability_rules` row (which stores wall-clock `time` values expanded against
the tenant timezone — `AvailabilityEngine::expandRule:183-205`) and shifts every displayed booking.
There is no confirmation and no audit row. Gate on owner and warn explicitly.

**M6 — `after_commit => false` on every queue connection.** `config/queue.php` — database, redis,
sqs, beanstalkd all set it false. Jobs dispatched inside a transaction (e.g.
`Notifier::scheduleReminder` → `SendBookingReminder::dispatch()` at `:117`, which runs inside
`BookingService::create`'s transaction) are enqueued before commit. Today the reminder job no-ops on
a missing booking so it is benign, but it is a loaded gun for the next queued job someone adds. Set
`'after_commit' => true`.

**M7 — `SESSION_SECURE_COOKIE` is unset and undocumented.** `config/session.php:172` reads
`env('SESSION_SECURE_COOKIE')` → null → false. Not present in `.env.example`. HSTS
(`SecurityHeaders.php:27-29`) mitigates after the first visit but the cookie is still marked
non-secure. Add `SESSION_SECURE_COOKIE=true` to `.env.example` with a comment.

**M8 — Database password on the command line in backup and restore.**
`BackupDatabase.php:33-40` and `RestoreDatabase.php:41-48` both build
`mysqldump --password=<pw>` / `mysql --password=<pw>` and `exec()` it. The password is visible in
`ps` to every local user and lands in shell audit logs. Dumps are also written unencrypted to
`storage/app/backups/` and the local copy is **never deleted** — `prune()` (`:69-79`) only prunes the
remote disk. Use a `--defaults-extra-file` with 0600 permissions; delete the local temp after upload.

**M9 — The nightly backup silently degrades to local-only.** `BackupDatabase.php:56-59`
```php
} catch (\Throwable $exception) {
    $this->warn('Remote store skipped: '.$exception->getMessage());
    Storage::disk('local')->put(...);
}
```
`routes/console.php:15` schedules `db:backup --disk=s3` daily. `LAUNCH.md` lists S3 as deferred
pending AWS credentials, so on day one this catch fires every night, writes a `warn` nobody reads,
and stores the backup on the same disk as the database it is backing up. Fail the command (non-zero
exit) so the scheduler surfaces it, and don't schedule `--disk=s3` until the credentials exist.

**M10 — `claimOffer()` is a read-then-write with no lock or transaction.**
`BookingService.php:216-278` checks `isClaimable()` (`:227`), calls `create()`, then writes
`status = Claimed` (`:252-255`) and expires siblings (`:259-275`) — none of it atomic, none of it in
a transaction. Two concurrent claims of the *same* token both pass `isClaimable()`; the second is
saved only because `create()`'s slot check rejects it. That is defence by side effect. If the sibling
loop throws partway (e.g. `notifier->waitlistGone` hits Twilio), some siblings are superseded and
others are left `Sent` with the slot already taken — those customers get a claim link that 409s.
Wrap `:227-275` in a transaction with `lockForUpdate()` on the offer row.

**M11 — Rescheduling into an overlapping slot is rejected by the booking's own row.**
`BookingService::reschedule:193-201` calls `assertSlotOpen()`, which runs the availability engine —
and the engine subtracts *all* non-cancelled bookings for that staff member (`AvailabilityEngine:103-109`),
including the one being moved. Moving a 10:00 booking to 10:15 is therefore reported as unavailable.
Nudging an appointment by 15 minutes is a completely ordinary thing to want to do. Exclude
`$booking->id` from the engine's booking load for the reschedule path.

**M12 — `DEPLOY.md` step 7 references a package that is not installed.** `composer.json` has no
`laravel/horizon`; `DEPLOY.md` steps 7 and the rollback step both run `php artisan horizon:terminate`,
which will exit non-zero as an unknown command. `LAUNCH.md` correctly lists Horizon as deferred — the
deploy doc was not updated to match. Fix the doc or install the package.

**M13 — Manual bookings with a blank email collide.** `customers.email` is `NOT NULL` with
`unique(['tenant_id','email'])`. `StoreManualBookingRequest.php:26` allows
`customer_email` to be null when `customer_id` is absent (`required_without` + `nullable`), and
`BookingController::createCustomer:141` writes `$request->string('customer_email')->toString()` → `''`.
The first walk-in with no email saves; the second throws a duplicate-key `QueryException` → 500.
Make the column nullable (a unique index ignores NULLs) or require an email.

**M14 — Waitlist join 500s on an unparseable phone number.** `PublicBookingController::waitlist:222-227`
calls `findOrCreateCustomer` → `PhoneNumber::toE164` (`:293`) outside any try/catch. The `store()`
method wraps the same call and returns 422 (`:195-197`); `waitlist()` does not. Wrap it.

**M15 — `TenantSlug::generate` is a read-then-write race.** `TenantSlug.php:27-31` loops on
`exists()` then the caller inserts. Two simultaneous registrations of the same business name both
resolve to the same slug and the second hits the unique index → 500 on the registration form. Catch
the duplicate-key exception and retry with a random suffix.

**M16 — `matchCustomer()` fires on every keystroke.** `BookingIsland.vue:240-245` watches
`[details.email, details.phone]` with no debounce. Typing a 25-character email produces ~25 GETs
against a limiter of 60/min (`AppServiceProvider.php:68-70`). A customer who corrects a typo or fills
the phone field too can 429 themselves mid-form, and `matchCustomer` swallows the error
(`:235-237`) so the failure is invisible. Debounce to 500ms.

**M17 — N+1s and unbounded loads on the main screens.**
- `BookingController::show:74` → `waitlistPreview()` runs the full `rankedMatches` query (loading and
  filtering every active waitlist entry in PHP) on every booking detail view, to display a count.
- `HandleInertiaRequests::share:34` does `$user?->tenant`, re-querying the tenant that
  `ResolveTenant:29` already fetched (it uses `->tenant()->first()`, which does not set the relation)
  — one extra query on every request.
- `DashboardController:29-46` loads full collections (`$waitlistFilled`, `$finished`) to count them.
- `DiaryController:33-41` has no limit on the week view.
- `CustomerController::show:41` loads all bookings for a customer with no pagination.
- `BookingPayload::toArray` calls `loadMissing()` per booking (`:14`) — safe where the caller eager
  loads (all current callers do), lazy-loading per row if one ever forgets.

**M18 — Missing indexes on paths that will get hot.**
- `waitlist_entries` — only `tenant_id` is indexed. `WaitlistOfferer::rankedMatches:92-100` filters
  `tenant_id + service_id + is_active + expires_at` then `->get()` and filters/sorts in PHP. Add
  `(tenant_id, service_id, is_active)`.
- `bookings` — no index supporting `ReleaseExpiredPendingBookings`' `status + created_at` scan
  (`:20-23`), which runs **every minute across all tenants**. Add `(status, created_at)`.
- `bookings` — no index for the dashboard's `(deposit_status, deposit_paid_at)` sum
  (`DashboardController:36-40`).
- `services` — add `(tenant_id, is_active, sort_order)` for the public service list.
- The availability path itself is well covered by `(tenant_id, staff_id, starts_at)` and
  `(tenant_id, user_id, weekday)`.

**M19 — `MoneyCast` silently defaults to GBP without a tenant context.** `MoneyCast.php:15-28` falls
back to `'GBP'` when `current_tenant()` is null — which is the normal state inside queue jobs and
console commands. Harmless while every tenant is GBP; wrong the day one isn't. Read the currency from
the model's tenant relation rather than ambient state.

**M20 — Booking CSV import bypasses every availability and conflict check.**
`BookingCsvImporter::walk:97-112` writes `Booking` rows via `forceFill` with no slot check, so an
import can create overlapping bookings on the same staff member at the same time, which then render
stacked in the diary with no warning. The line-by-line `preg_split` + `str_getcsv` parse (`:41`, `:53`)
also breaks on any quoted field containing a newline — a note field pasted from another system. Use
a real CSV reader over the file handle and report conflicts in the dry run.

---

## Low

Cleanliness, consistency, maintainability.

**L1 — Two competing component systems.** `resources/js/Components/*.vue` (Breeze scaffolding:
`PrimaryButton`, `SecondaryButton`, `DangerButton`, `Modal`, `TextInput`, `Checkbox`, `InputLabel`,
`InputError`, `NavLink`, `ResponsiveNavLink`, `Dropdown`, `DropdownLink`, `ApplicationLogo`) coexists
with `resources/js/Components/ui/*.vue` (the design-pass system). `MoneyText.vue` and `ui/Money.vue`
are byte-identical. `DESIGN.md` says shared components live under `ui/`; half the app didn't get the
memo. Pick one, migrate, delete the other.

**L2 — 11 components are referenced by nothing:**
`Components/Checkbox.vue`, `ui/Badge.vue`, `ui/Checkbox.vue`, `ui/DatePicker.vue`, `ui/Skeleton.vue`,
`ui/Table.vue`, `ui/Tabs.vue`, `ui/Textarea.vue`, `ui/TimePicker.vue`, `ui/Toggle.vue`,
`Layouts/AuthenticatedLayout.vue`. `ui/Skeleton.vue` being unused is notable — `DESIGN.md` requires
every screen to expose a loading state.

**L3 — Unused and conflicting dependencies.**
- `laravel/sanctum` — no API routes, no `HasApiTokens`, zero references. Remove.
- `laravel/breeze` (require-dev) — scaffolding package, already applied. Remove.
- `tailwindcss ^3.2.1` **and** `@tailwindcss/vite ^4.0.0` are both installed, alongside a v3-style
  `postcss.config.js` + `autoprefixer`. A half-finished Tailwind 3→4 migration. Resolve to one major
  version.

**L4 — Skeleton tests still present.** `tests/Feature/ExampleTest.php` and
`tests/Unit/ExampleTest.php` — one assertion each, asserting the framework boots. Delete.

**L5 — A test-only hook lives in the production money path.** `BookingService::$afterLock` and
`withAfterLock()` (`:32`, `:49-56`) exist solely for `BookingConcurrencyTest`. An arbitrary closure
invoked inside the booking transaction (`:85-87`) is not something that should ship. Replace with a
real concurrency test (H6) and delete the hook.

**L6 — Float arithmetic on money in the Vue layer.** `BookingIsland.vue:142-147` computes the
remainder with `Intl.NumberFormat(...).format(left / 100)`. Display-only and correct for realistic
values, but it is the one place the integer-pence discipline is broken client-side, and it bypasses
the `Money.formatted` string the API already provides. (`StripeBillingGateway.php:84` is the PHP
equivalent — see H13.) Everywhere else — `MoneyCast`, `Money`, `money.ts`, `MoneyText.vue` — is
correctly integer pence end to end.

**L7 — `is_super_admin` and `role` are mass-assignable on `User`.** `User.php:23-32`. Not currently
reachable — every write path goes through a form request with an explicit rule list, and
`StaffController::store:41-48` hardcodes `role` *after* the spread — but `$fillable` is the wrong
place for a privilege flag. Move both to `$guarded` and set them with `forceFill`.

**L8 — `SearchController` passes user wildcards through.** `:20-21` interpolates `$q` into
`like '%'.$q.'%'`. Parameter-bound, so no injection — but `%` and `_` typed by the user act as
wildcards. Escape them.

**L9 — `/health` is unauthenticated and unthrottled.** `routes/web.php:45`. Returns infrastructure
state to anyone. Low value to an attacker, but it should at least be rate limited or IP-restricted to
the monitor.

**L10 — `TenantCloner` copies soft-deleted services.** `TenantCloner.php:22` uses
`Service::withoutGlobalScopes()`, which strips `SoftDeletingScope` as well as the tenant scope, so
`replicate()` carries deleted rows (and their `deleted_at`) into the new tenant. Use
`->withoutGlobalScope(TenantScope::class)` rather than the blanket form.

**L11 — Onboarding routes skip the subscription gate.** `routes/web.php:48-54` applies
`['auth','tenant']` but not `subscribed`, so an expired tenant can still write through the onboarding
endpoints. Narrow window (they would have to be mid-onboarding at expiry) but inconsistent.

**L12 — `str_getcsv()` called without `$escape`.** `CustomerCsvImporter.php:46`,
`BookingCsvImporter.php:53`. Deprecated in PHP 8.4; `composer.json` requires `php: ^8.3`.

**L13 — `UpdateServiceRequest`'s deposit guard escapes on a partial update.** `:37-44` returns early
unless **both** `price` and `deposit_amount` are present. `StoreServiceRequest` uses `lte:price`
(`:29`), so create is safe, but a PATCH lowering `price` alone leaves `deposit_amount > price`.

**L14 — `AvailabilityCache::bust()` is a read-then-write.** `:27-32` — two concurrent bookings both
read v1 and both write v2, so the version increments once instead of twice. Benign (the cache is
still busted), but `Cache::increment()` is the right call.

---

## Answers to the specific questions

**Every model uses `BelongsToTenant`?** Yes for all tenant-scoped models: `Booking`, `Customer`,
`Subject`, `Service`, `AvailabilityRule`, `TimeOff`, `Message`, `WaitlistEntry`, `SlotOffer`, `User`.
Four models deliberately don't and are safe: `Tenant` (is the tenant), `StripeEvent` /
`BillingEvent` (platform-level webhook ledgers keyed on a unique provider event id), `AuditLog` and
`WebhookFailure` (platform-level, super-admin only, `target_tenant_id` is data not scope).
`User` overrides `tenantScopeFailClosed()` to **false** (`User.php:46-49`) so identity lookups work
pre-auth — correct in intent, but it means any `User::query()` without a context is unscoped. Today
every such path either sets a context first or passes an explicit `tenant_id`.

**All 40 `withoutGlobalScope` sites reviewed.** Every one either passes an explicit
`->where('tenant_id', ...)` or keys on a globally-unique value (booking id, `public_token`, offer
`token`, `stripe_payment_intent_id`, Twilio SID). Two are flagged above:
`StripeEventProcessor.php:34` (C2, unconstrained fallback) and `TwilioStatusController.php:21` (H7,
unauthenticated). The rest are justified: `AvailabilityEngine` is deliberately context-free per
`DECISIONS.md` and always passes `tenant_id` explicitly.

**Raw SQL.** Three sites, all safe: `TenantScope.php:27` (`whereRaw('0 = 1')`, constant),
`HealthController.php:31` (`select 1`), `SuperAdminController.php:79`
(`DB::table('failed_jobs')`, platform-level by design). **No SQL injection found.**

**Route-model binding across tenants.** Correct — the global scope applies to implicit binding, so
`/bookings/{booking}`, `/customers/{customer}`, `/services/{service}`, `/staff/{staff}`,
`/time-off/{time_off}` all **404** across tenants rather than 403. Verified by
`TenantIsolationTest.php:33`. `Route::model('staff', User::class)` and
`Route::model('time_off', TimeOff::class)` (`AppServiceProvider.php:65-66`) preserve this.

**Cache keys.** Tenant-scoped and versioned — `AvailabilityCache::key()` includes tenant id plus a
per-tenant version integer. No cross-tenant key found.

**Broadcast channels.** None. No `routes/channels.php`, no Echo, `BROADCAST_CONNECTION=log`. Nothing
to leak.

**CSRF on the public islands.** Handled — `booking.ts:9`, `manage.ts:8`, `offer.ts:8` all read the
`csrf-token` meta from `public-shell.blade.php:6`. Note that CSRF is **never exercised by the test
suite** (`VerifyCsrfToken` short-circuits under `runningUnitTests()`), so this is verified by reading
only; confirm in a browser before launch.

**Historical price.** Preserved correctly — `price_at_booking` and `deposit_at_booking` are snapshot
onto the booking row at creation (`BookingService.php:103-104`) and every read path uses the snapshot,
not `service->price`. This is right.

**Stripe Connect direct charges.** Correct — `createPaymentIntent` passes
`['stripe_account' => $tenant->stripe_account_id]` (`StripeConnectGateway.php:77-79`) and
`application_fee_amount` uses `intdiv` (`:71`), integer-safe. Refunds pass the same header (`:91-93`).

**Double charge by retry/refresh.** No. Stripe PaymentIntents are single-charge, and a refreshed
checkout that tries to rebook hits `assertSlotOpen` against its own pending booking and gets a 409.
The duplicated-webhook case is guarded by the `stripe_events.event_id` unique index plus the
`processed_at` check in `ProcessStripeEvent:20-25`, and `paymentSucceeded` short-circuits on
`status === Confirmed` (`:39`). The real webhook risk is C2 (forgery), not duplication.

**Refund window boundaries.** `outsideRefundWindow` (`:291-296`) computes
`starts_at->utc()->subHours($hours)->isFuture()` — refund granted strictly *before* the cutoff, no
refund at or after it. The comparison is UTC-to-UTC and consistent. The one wrinkle: subtracting 48
*absolute* hours means that across a DST change the cutoff lands an hour off the customer's
wall-clock expectation. For a 48-hour window that is not worth fixing; note it if the window ever
becomes "2 days".

**Time and timezones generally.** `config/app.php` sets `'timezone' => 'UTC'`, all columns are
`dateTime`/`timestamp` in UTC, and display consistently goes through `->timezone($tenant->timezone)`.
`now()` and `Carbon::now()` are both UTC, and the code never uses database `NOW()`. I found no
UTC/local mixing bug. `AvailabilityEngine` handles DST correctly by expanding rules on the local
clock (`expandRule:183-205`) and comparing UTC instants; `alignUp:250-260` aligns on local minutes,
matching the `DECISIONS.md` intent. Reminder delays are absolute UTC instants
(`Notifier.php:114-117`), so they survive clock changes. Two soft spots: the booking horizon is
`now()->addDays(N)` in UTC (`AvailabilityEngine:43`) so the horizon edge shifts by an hour twice a
year, and `SendDailyAgendas` gates on `$now->hour !== 7` with an hourly cron and no
`withoutOverlapping()` — a delayed or doubled scheduler run sends two agendas.

**Failure modes summary:**

| Scenario | Behaviour | Verdict |
|---|---|---|
| Stripe down during checkout | Booking created, no payment intent, "Almost there", auto-cancelled in 15 min | **silent** (C5) |
| Twilio down when a reminder fires | Job throws → 3 retries → `failed_jobs` → `/admin/failures` | loud ✓ |
| Twilio down during booking creation | Exception inside transaction → booking rolled back | loud, but loses the booking (C4) |
| Twilio down during cancel | Refund already sent → transaction rolls back → money gone, booking live | **silent + money loss** (C3) |
| Queue worker dies | `/health` still green, webhooks queue up unprocessed, bookings auto-cancel | **silent** (H12) |
| Webhook before the booking commits | PaymentIntent is created post-commit, so it can't happen | safe ✓ |
| Two identical webhooks | Unique `event_id` + `processed_at` + status short-circuit | safe ✓ |
| Webhook arrives while the DB is down | 200 returned, event discarded, Stripe never retries | **silent + money loss** (C6) |
| Tenant deletes a service with future bookings | Soft-deleted, service name blanks everywhere | **silent** (H4) |
| Card declined after the slot was held | Booking stays pending, released after 15 min, customer texted "cancelled" | works, but the copy is wrong (H9) |
| DB connection drops mid-transaction | Transaction rolls back; if it drops *after* a Stripe refund, C3 applies | mostly safe |

---

## Test quality

97 tests, 457 assertions, all green. They are real tests — they assert on database state and HTTP
responses, not on mocks — and the availability engine and waitlist ranking suites are genuinely good.
But the environment they run in hides the highest-risk failures.

**The environment is the problem.** `phpunit.xml` sets `DB_CONNECTION=sqlite` `:memory:` and
`QUEUE_CONNECTION=sync`. Production is MySQL with real workers. That single difference means:

- `lockForUpdate()` is a documented no-op, so **no test exercises the booking lock** (H6).
- FK cascades behave differently, so **nothing catches C8** (profile delete wiping bookings).
- Jobs run inside the HTTP request where the tenant context is set, so **nothing can catch C9**
  (fail-open scope in workers) — including the test that appears to assert fail-closed
  (`TenantIsolationTest.php:36-40`), which passes only because `runningUnitTests()` is true.
- `VerifyCsrfToken` short-circuits, so CSRF is never verified.

**Tests that don't test what their name says:**
- `BookingConcurrencyTest.php:72` — "after a competing insert inside the lock" injects the insert
  through a production test hook, in the same transaction. It tests the re-check, not the lock.
- `BookingConcurrencyTest.php:100` — "two public requests" are sequential.
- `WaitlistTest.php:65` — "two simultaneous claims" are sequential POSTs.
- `TenantIsolationTest.php:36` — passes for the wrong reason (above).
- `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php` — assert nothing meaningful.

**Critical paths with zero coverage:**
- Tenant isolation for anything other than `Service`. There is no isolation test for `Booking`,
  `Customer`, `Subject`, `Message`, `WaitlistEntry`, `SlotOffer`, `TimeOff`, `AvailabilityRule`
  or `User`.
- `StripeEventProcessor` cross-tenant/forged metadata (C2) and the unconstrained-`first()` branch.
- `FakeStripeGateway` being reachable outside `testing` (C1).
- `/twilio/status` (H7) — no test at all.
- Impersonation start/stop (M3).
- CSV import failure paths (H10) — the phone-parse rollback in particular.
- `CustomerPrivacyController::destroy` cascade behaviour (H3).
- `ProfileController::destroy` cascade behaviour (C8).
- Reschedule + reminder interaction (H5).
- `ReleaseExpiredPendingBookings` waitlist/notification side effects (H9).
- Any refund path at all — `refundPaymentIntent` is exercised only via `FakeStripeGateway`'s
  `$refunds` array; the rollback-after-refund case (C3) is untested.
- Availability engine edge cases: zero-duration/very long services, a service whose buffer exceeds
  the working window, staff with overlapping rules on the same day, DST dates other than the two in
  `DECISIONS.md`, the horizon boundary, the `min_notice_hours` boundary, and soft-deleted or
  deactivated staff mid-day.

**What I'd add first:** one MySQL-backed test that forks two processes booking the same slot (H6);
one test that runs a real queue worker with no tenant context and asserts zero rows (C9); one
isolation test per tenant-owned model, generated from a list.

---

## Prioritised fix list

**Before any real customer touches this — non-negotiable:**

1. **C1** — `FakeStripeGateway` in production + hardcoded webhook signature. One-line bind change plus deleting the fake's signature branch. Highest severity, lowest effort.
2. **C7** — `JSON_HEX_TAG` on `public-shell.blade.php:35`. One line. Card-skimming vector on the payment page.
3. **C2** — Bind the Connect webhook to the tenant's `stripe_account_id` and check the amount.
4. **C6** — Return 500 (not 200) when the webhook can't be stored.
5. **C8** — Change `bookings.staff_id` to `restrictOnDelete`; block last-owner self-deletion.
6. **C10** — Neuter or remove `customer-match`; stop overwriting customer name/phone from public input.
7. **C3** — Move the Stripe refund out of the cancel transaction.
8. **C4** — Queue all mail and SMS; take notifications out of the booking transaction.
9. **C5** — Let `createPaymentIntent` failure surface; never show "Almost there" with no way to pay.
10. **H1** — Throttle register, forgot-password, reset-password.
11. **C9** — Make the tenant scope fail closed in console/queue.

**Before the first *busy* week:**

12. **H6** — Add the unique constraint that makes double booking structurally impossible, plus deadlock retry, plus a real MySQL concurrency test.
13. **H9** — Stop blasting the waitlist and texting cancellations on abandoned checkouts.
14. **H5** — Reschedule the reminder on reschedule.
15. **H2** — Tenant-scope every `Rule::exists`.
16. **H3, H4** — Guard destructive deletes (customer erasure, service deletion) against future bookings.
17. **H12** — Make the health check actually check the queue.
18. **H7** — Verify the Twilio signature.
19. **H10, H11** — Validate the import upload and stop coercing bad prices to zero.
20. **H8** — Introduce owner-vs-staff authorization.

**Before the tenth tenant:**

21. **H13, M4, M17, M18** — Stripe call caching, super-admin N+1, eager loads, indexes.
22. **H14** — Decide on per-tenant email uniqueness. This one gets harder with every signup.
23. **M1, M5, M6, M10, M11** — Cache busting, timezone gating, `after_commit`, offer-claim atomicity, self-overlap on reschedule.
24. **M8, M9, M12** — Backup credentials handling, backup failure surfacing, deploy doc.

**When there's slack:** everything in Low. Start with L1/L2/L3 — deleting the duplicate component
system and the unused dependencies makes every subsequent change cheaper.

---

## Honest assessment

**No — I would not put a real business's bookings and money through this code today.** Not because
it is badly built: the architecture is sound, the tenant scope is a genuine defence-in-depth design,
money is integer pence essentially everywhere, the availability engine's timezone and DST handling is
better than most production systems I've read, and historical price is correctly snapshot onto the
booking row. Six passes produced something coherent, and the untidiness is minor — no dead debug
code, no TODOs, no commented-out blocks, one real duplicated-component mess. The problem is that the
things that were rushed were rushed in exactly the places that end businesses. An empty
`STRIPE_SECRET` silently swaps in a fake payment gateway whose webhook signature check is the string
`t=1,v1=test`, which is a remote, unauthenticated, cross-tenant booking-and-payment forgery on a
misconfigured deploy — and a `config:cache` at the wrong moment is enough to cause it. The Connect
webhook confirms bookings from attacker-controlled metadata with no account binding. A Stripe refund
runs inside a transaction that a Twilio timeout can roll back, which means money leaves the account
with no record that it did. Stripe going down at checkout tells the customer "Almost there" and then
quietly cancels them. An unauthenticated URL returns any customer's phone number and their dog's
name. And "delete your account" hard-deletes the whole diary through a foreign key cascade nobody
tested, because the tests run on SQLite where that cascade behaves differently.

The green suite is the most misleading artifact in the repository. Ninety-seven tests pass, and they
are real tests — but they run on SQLite where `lockForUpdate()` is documented to do nothing, with a
sync queue where the fail-open tenant scope cannot fire, and with CSRF disabled. The three tests
named for concurrency all call the method twice in a row. So the suite's confidence is concentrated
precisely where the risk isn't, and the two mechanisms the whole design rests on — the booking lock
and tenant isolation in background work — have no evidence behind them at all.

The ten Critical items are, individually, small fixes: several are one line, most are under an
hour, and the largest (queueing notifications properly) is a day. Call it three to five focused days
including the MySQL and queue-worker tests that should have existed from the start. Do that work, run
the suite once against real MySQL with a real worker, and I would be comfortable. Today, with an
empty `STRIPE_SECRET` on a Forge box, the first person to POST a JSON body at `/stripe/webhook` owns
every booking on the platform.
