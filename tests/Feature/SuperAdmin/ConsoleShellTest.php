<?php

use App\Enums\BookingStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookFailure;
use App\Support\TenantContext;
use Inertia\Testing\AssertableInertia;

function aShellAdmin(): User
{
    return User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::Owner,
        'is_super_admin' => true,
    ]);
}

it('counts the rail badges from the database rather than shipping a number', function () {
    Tenant::factory()->count(3)->create();
    WebhookFailure::query()->create(['source' => 'stripe', 'type' => 'charge.failed', 'message' => 'nope']);
    WebhookFailure::query()->create(['source' => 'twilio', 'type' => 'status', 'message' => 'nope']);

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navCounts.tenants', 3)
            ->where('navCounts.failures', 2));
});

it('leaves the rail badges off for a salon owner, who has different counts entirely', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Owner]);

    actingAsTenant($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('navCounts.tenants'));
});

it('counts live tenants by whether their booking page is actually published', function () {
    Tenant::factory()->count(2)->create(['booking_page_live' => true]);
    Tenant::factory()->create(['booking_page_live' => false]);

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.live_tenants', 2));
});

it('counts only this month\'s texts, and only the texts', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    $message = fn (MessageChannel $channel, string $to) => Message::query()->create([
        'channel' => $channel,
        'type' => MessageType::Reminder,
        'status' => MessageStatus::Sent,
        'to' => $to,
        'body' => 'body',
    ]);

    $message(MessageChannel::Sms, '07700900000');
    $message(MessageChannel::Email, 'alex@example.com');
    $message(MessageChannel::Sms, '07700900001')
        ->forceFill(['created_at' => now()->subMonth()->startOfMonth()])
        ->save();

    $context->clear();

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.sms_this_month', 1));
});

it('leaves a cancelled appointment out of today\'s bookings', function () {
    $salon = aDiarySalon();

    aDiaryBooking($salon, '2026-08-19 14:00', '2026-08-19 15:00');
    aDiaryBooking($salon, '2026-08-19 16:00', '2026-08-19 17:00', ['status' => BookingStatus::Cancelled]);
    aDiaryBooking($salon, '2026-08-26 14:00', '2026-08-26 15:00');

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.bookings_today', 1));
});

it('reports send failures as the sum of both lists the failures screen shows', function () {
    WebhookFailure::query()->create(['source' => 'stripe', 'type' => 'charge.failed', 'message' => 'nope']);

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.send_failures', 1));
});

it('tells the console which environment it is looking at', function () {
    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('environment', app()->environment()));
});

it('names the build beside the environment, so a screenshot says which one it is', function () {
    config(['product.version' => '2.14.0']);

    $this->actingAs(aShellAdmin())
        ->get(route('super-admin.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('appVersion', '2.14.0'));
});
