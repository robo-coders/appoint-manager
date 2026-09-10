<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\CalendarFeedToken;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalendarFeedTokenService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

/**
 * @return array{tenant: Tenant, owner: User, staff: User, service: Service}
 */
function aSyncSalon(array $overrides = []): array
{
    $tenant = Tenant::factory()->create(array_merge([
        'name' => 'Paws and Whiskers',
        'timezone' => 'Europe/London',
        'onboarding_completed_at' => now(),
    ], $overrides));

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Erin MacKay',
        'role' => UserRole::Owner,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Sam Doherty',
        'role' => UserRole::Staff,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom',
        'sort_order' => 0,
    ]);

    return compact('tenant', 'owner', 'staff', 'service');
}

function aSyncBooking(array $salon, User $staff, string $when, array $overrides = []): Booking
{
    $starts = CarbonImmutable::parse($when, 'Europe/London');

    $context = app(TenantContext::class);
    $context->set($salon['tenant']);

    try {
        $customer = Customer::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'name' => $overrides['customer'] ?? 'Claire Donnelly',
            'email' => 'claire.'.Str::random(6).'@example.test',
            'phone' => '+447700900123',
        ]);

        $booking = Booking::factory()->create(array_merge([
            'tenant_id' => $salon['tenant']->id,
            'staff_id' => $staff->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'starts_at' => $starts->utc(),
            'ends_at' => $starts->addHour()->utc(),
            'status' => BookingStatus::Confirmed,
        ], array_diff_key($overrides, ['customer' => true])));

        return $booking->setRelation('customer', $customer);
    } finally {
        $context->clear();
    }
}

function feedUrl(Tenant $tenant, string $token): string
{
    return route('ical.feed', ['tenantSlug' => $tenant->slug, 'token' => $token]);
}

function activeToken(Tenant $tenant, string $scope, ?int $staffId = null): CalendarFeedToken
{
    return CalendarFeedToken::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('scope', $scope)
        ->when($staffId === null, fn ($q) => $q->whereNull('staff_id'), fn ($q) => $q->where('staff_id', $staffId))
        ->whereNull('revoked_at')
        ->firstOrFail();
}

/**
 * Unfold, then split into a calendar and its events.
 *
 * @return array{properties: array<string, string>, events: list<array<string, string>>}
 */
function parseIcs(string $body): array
{
    expect($body)->toEndWith("\r\n");
    expect(preg_match('/(?<!\r)\n/', $body))->toBe(0);

    $lines = array_values(array_filter(
        explode("\r\n", str_replace("\r\n ", '', $body)),
        fn (string $line) => $line !== '',
    ));

    expect($lines[0])->toBe('BEGIN:VCALENDAR');
    expect($lines[count($lines) - 1])->toBe('END:VCALENDAR');

    $properties = [];
    $events = [];
    $event = null;

    foreach ($lines as $line) {
        if ($line === 'BEGIN:VCALENDAR' || $line === 'END:VCALENDAR') {
            continue;
        }

        if ($line === 'BEGIN:VEVENT') {
            expect($event)->toBeNull();
            $event = [];

            continue;
        }

        if ($line === 'END:VEVENT') {
            expect($event)->not->toBeNull();
            $events[] = $event;
            $event = null;

            continue;
        }

        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');

        if ($event === null) {
            $properties[$name] = $value;
        } else {
            $event[$name] = $value;
        }
    }

    expect($event)->toBeNull();

    return ['properties' => $properties, 'events' => $events];
}

/*
|--------------------------------------------------------------------------
| The settings screen
|--------------------------------------------------------------------------
*/

it('shows the owner one feed per bookable person plus the combined feed', function () {
    $salon = aSyncSalon();

    $this->actingAs($salon['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/CalendarSync/Index')
            ->has('feeds', 2)
            ->where('feeds.0.staffName', 'Erin MacKay')
            ->where('feeds.0.role', 'Owner')
            ->where('feeds.0.isOwn', true)
            ->where('feeds.1.staffName', 'Sam Doherty')
            ->where('feeds.1.role', 'Staff')
            ->where('contentMode', 'full')
            ->where('verticalNoun', 'salon')
            ->where('sampleService', 'Full groom')
            ->where('firstSyncHours', config('calendar_sync.first_sync_note_hours'))
            ->where('feeds.0.lastPulledAt', null)
            ->where('feeds.0.url', fn (string $url) => str_starts_with($url, 'http')
                && str_contains($url, '/ical/'.$salon['tenant']->slug.'/')
                && str_ends_with($url, '.ics'))
            ->where('salonFeed.url', fn (string $url) => str_ends_with($url, '.ics')));
});

it('mints the missing tokens on first load and reuses them on the next', function () {
    $salon = aSyncSalon();

    expect(CalendarFeedToken::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($salon['owner'])->get(route('settings.calendar-sync'))->assertOk();

    $first = CalendarFeedToken::query()->withoutGlobalScopes()->pluck('token')->sort()->values()->all();

    expect($first)->toHaveCount(3);

    $this->actingAs($salon['owner'])->get(route('settings.calendar-sync'))->assertOk();

    $second = CalendarFeedToken::query()->withoutGlobalScopes()->pluck('token')->sort()->values()->all();

    expect($second)->toBe($first);
});

it('provisions a token for somebody added after the screen was last opened', function () {
    $salon = aSyncSalon();

    $this->actingAs($salon['owner'])->get(route('settings.calendar-sync'))->assertOk();

    $joiner = User::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Ana Duarte',
        'role' => UserRole::Staff,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $this->actingAs($salon['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('feeds', 3));

    expect(activeToken($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $joiner->id))->not->toBeNull();
});

it('leaves out anybody who is not active', function () {
    $salon = aSyncSalon();
    $salon['staff']->forceFill(['is_active' => false])->save();

    $this->actingAs($salon['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('feeds', 1));
});

it('still offers the combined feed to a salon of one', function () {
    $salon = aSyncSalon();
    $salon['staff']->forceFill(['is_active' => false])->save();

    $this->actingAs($salon['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('feeds', 1)
            ->where('salonFeed.url', fn (string $url) => str_ends_with($url, '.ics')));
});

it('keeps calendar sync behind a login', function () {
    $this->get(route('settings.calendar-sync'))->assertRedirect(route('login'));
    $this->post(route('settings.calendar-sync.regenerate'), ['scope' => 'salon'])->assertRedirect(route('login'));
    $this->post(route('settings.calendar-sync.content-mode'), ['mode' => 'full'])->assertRedirect(route('login'));
});

it('keeps one salon out of another salon feeds', function () {
    $mine = aSyncSalon();
    $theirs = aSyncSalon();

    $this->actingAs($theirs['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('feeds.0.url', fn (string $url) => str_contains($url, '/ical/'.$theirs['tenant']->slug.'/')
                && ! str_contains($url, '/ical/'.$mine['tenant']->slug.'/')));

    expect(CalendarFeedToken::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $mine['tenant']->id)
        ->count())->toBe(0);
});

it('refuses to regenerate a feed for somebody in another salon', function () {
    $mine = aSyncSalon();
    $theirs = aSyncSalon();

    $this->actingAs($theirs['owner'])
        ->post(route('settings.calendar-sync.regenerate'), [
            'scope' => CalendarFeedToken::SCOPE_STAFF,
            'staff_id' => $mine['staff']->id,
        ])
        ->assertSessionHasErrors('staff_id');
});

it('rejects a scope and a mode it does not recognise', function () {
    $salon = aSyncSalon();

    $this->actingAs($salon['owner'])
        ->post(route('settings.calendar-sync.regenerate'), ['scope' => 'everything'])
        ->assertSessionHasErrors('scope');

    $this->actingAs($salon['owner'])
        ->post(route('settings.calendar-sync.regenerate'), ['scope' => CalendarFeedToken::SCOPE_STAFF])
        ->assertSessionHasErrors('staff_id');

    $this->actingAs($salon['owner'])
        ->post(route('settings.calendar-sync.content-mode'), ['mode' => 'everything'])
        ->assertSessionHasErrors('mode');
});

/*
|--------------------------------------------------------------------------
| Regenerating
|--------------------------------------------------------------------------
*/

it('retires the old link and issues a working one', function () {
    $salon = aSyncSalon();
    $this->actingAs($salon['owner'])->get(route('settings.calendar-sync'))->assertOk();

    $old = activeToken($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)->token;

    $this->get(feedUrl($salon['tenant'], $old))->assertOk();

    $this->actingAs($salon['owner'])
        ->post(route('settings.calendar-sync.regenerate'), [
            'scope' => CalendarFeedToken::SCOPE_STAFF,
            'staff_id' => $salon['staff']->id,
        ])
        ->assertRedirect();

    $new = activeToken($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)->token;

    expect($new)->not->toBe($old);

    $this->get(feedUrl($salon['tenant'], $new))->assertOk();

    $gone = $this->get(feedUrl($salon['tenant'], $old))->assertStatus(410);

    expect($gone->getContent())->toBe('This calendar link has been regenerated and is no longer valid.')
        ->and($gone->headers->get('Content-Type'))->toContain('text/plain');
});

it('never leaves two live tokens for one scope, however often it is pressed', function () {
    $salon = aSyncSalon();
    $tokens = app(CalendarFeedTokenService::class);

    $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON);

    foreach (range(1, 3) as $ignored) {
        $tokens->regenerate($salon['tenant'], CalendarFeedToken::SCOPE_SALON);
    }

    $rows = CalendarFeedToken::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('scope', CalendarFeedToken::SCOPE_SALON)
        ->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->whereNull('revoked_at'))->toHaveCount(1)
        ->and($rows->pluck('token')->unique())->toHaveCount(4);
});

it('takes a row lock before it replaces a token', function () {
    $salon = aSyncSalon();
    $tokens = app(CalendarFeedTokenService::class);
    $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $tokens->regenerate($salon['tenant'], CalendarFeedToken::SCOPE_SALON);

    $locking = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'for update'));

    DB::disableQueryLog();

    expect($locking)->not->toBeEmpty()
        ->and($locking->filter(fn (string $sql) => str_contains($sql, 'calendar_feed_tokens')))->not->toBeEmpty();
});

it('hands the same token back when two loads both try to provision one', function () {
    $salon = aSyncSalon();
    $tokens = app(CalendarFeedTokenService::class);

    $first = $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id);
    $second = $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id);

    expect($second->getKey())->toBe($first->getKey())
        ->and(CalendarFeedToken::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('mints a token of the configured length from the configured alphabet', function () {
    $salon = aSyncSalon();

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    expect(strlen($token))->toBe((int) config('calendar_sync.token_length'))
        ->and(preg_match('/^[A-Za-z0-9]+$/', $token))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The feed itself
|--------------------------------------------------------------------------
*/

it('serves a parseable calendar with one event per appointment', function () {
    $salon = aSyncSalon();
    aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    $response = $this->get(feedUrl($salon['tenant'], $token))->assertOk();
    $calendar = parseIcs($response->getContent());

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))
        ->toContain('max-age='.config('calendar_sync.ics_cache_seconds'));

    expect($calendar['properties'])
        ->toHaveKey('VERSION', '2.0')
        ->toHaveKey('PRODID', '-//'.config('product.name').'//Calendar Sync//EN')
        ->toHaveKey('CALSCALE', 'GREGORIAN')
        ->toHaveKey('METHOD', 'PUBLISH')
        ->toHaveKey('X-WR-TIMEZONE', 'Europe/London')
        ->toHaveKey('X-WR-CALNAME', $salon['tenant']->name.' — Sam Doherty');

    expect($calendar['events'])->toHaveCount(1);

    $event = $calendar['events'][0];

    expect($event['DTSTART'])->toBe('20260310T093000Z')
        ->and($event['DTEND'])->toBe('20260310T103000Z')
        ->and($event['SUMMARY'])->toBe('Claire Donnelly — Full groom')
        ->and($event['SEQUENCE'])->toBe('0')
        ->and($event['UID'])->toEndWith('@'.config('calendar_sync.uid_domain'))
        ->and($event)->toHaveKey('DTSTAMP');
});

it('produces a valid empty calendar when there is nothing booked', function () {
    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $calendar = parseIcs($this->get(feedUrl($salon['tenant'], $token))->assertOk()->getContent());

    expect($calendar['events'])->toBe([])
        ->and($calendar['properties'])->toHaveKey('VERSION', '2.0')
        ->and($calendar['properties'])->toHaveKey('X-WR-CALNAME', $salon['tenant']->name.' — All staff');
});

it('combines every staff member into the salon feed', function () {
    $salon = aSyncSalon();
    aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');
    aSyncBooking($salon, $salon['owner'], '2026-03-10 11:00:00');

    $tokens = app(CalendarFeedTokenService::class);
    $salonToken = $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)->token;
    $staffToken = $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)->token;

    expect(parseIcs($this->get(feedUrl($salon['tenant'], $salonToken))->getContent())['events'])->toHaveCount(2)
        ->and(parseIcs($this->get(feedUrl($salon['tenant'], $staffToken))->getContent())['events'])->toHaveCount(1);
});

it('leaves out cancelled, no-show, declined, pending and long-past appointments', function () {
    $salon = aSyncSalon();

    $kept = aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');
    aSyncBooking($salon, $salon['staff'], '2026-03-10 11:00:00', ['status' => BookingStatus::Cancelled]);
    aSyncBooking($salon, $salon['staff'], '2026-03-10 12:00:00', ['status' => BookingStatus::NoShow]);
    aSyncBooking($salon, $salon['staff'], '2026-03-10 13:00:00', ['status' => BookingStatus::Declined]);
    aSyncBooking($salon, $salon['staff'], '2026-03-10 14:00:00', ['status' => BookingStatus::Pending]);
    aSyncBooking($salon, $salon['staff'], '2026-02-01 09:00:00', ['status' => BookingStatus::Completed]);

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    $calendar = parseIcs($this->get(feedUrl($salon['tenant'], $token))->getContent());

    expect($calendar['events'])->toHaveCount(1)
        ->and($calendar['events'][0]['UID'])->toBe($kept->id.'@'.config('calendar_sync.uid_domain'));
});

it('keeps an appointment that finished in the trailing window', function () {
    $salon = aSyncSalon();
    aSyncBooking($salon, $salon['staff'], '2026-03-03 06:00:00', ['status' => BookingStatus::Completed]);

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    expect(parseIcs($this->get(feedUrl($salon['tenant'], $token))->getContent())['events'])->toHaveCount(1);
});

it('carries no personal data at all in busy-only mode', function () {
    $salon = aSyncSalon();
    $booking = aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');
    $customer = $booking->customer;

    $salon['tenant']->forceFill(['settings' => ['calendar_feed_content_mode' => 'busy_only']])->save();

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    $body = $this->get(feedUrl($salon['tenant'], $token))->getContent();
    $calendar = parseIcs($body);

    expect($calendar['events'])->toHaveCount(1)
        ->and($calendar['events'][0]['SUMMARY'])->toBe('Busy')
        ->and($calendar['events'][0])->not->toHaveKey('DESCRIPTION');

    expect($body)
        ->not->toContain($customer->name)
        ->not->toContain((string) $customer->email)
        ->not->toContain((string) $customer->phone)
        ->not->toContain('Full groom')
        ->not->toContain('ATTENDEE')
        ->not->toContain('ORGANIZER');
});

it('changes what the feed says the moment the mode is switched', function () {
    $salon = aSyncSalon();
    aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    expect(parseIcs($this->get(feedUrl($salon['tenant'], $token))->getContent())['events'][0]['SUMMARY'])
        ->toBe('Claire Donnelly — Full groom');

    $this->actingAs($salon['owner'])
        ->post(route('settings.calendar-sync.content-mode'), ['mode' => 'busy_only'])
        ->assertRedirect();

    expect($salon['tenant']->fresh()->settings['calendar_feed_content_mode'])->toBe('busy_only')
        ->and(parseIcs($this->get(feedUrl($salon['tenant'], $token))->getContent())['events'][0]['SUMMARY'])
        ->toBe('Busy');
});

it('escapes a name that would otherwise break the file, and folds a long line', function () {
    $salon = aSyncSalon();
    $salon['service']->forceFill(['name' => str_repeat('Full groom; the long one, ', 6)])->save();
    aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00', ['customer' => 'Smith, J']);

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    $body = $this->get(feedUrl($salon['tenant'], $token))->getContent();

    foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    expect($body)->toContain('Smith\\, J')
        ->and(parseIcs($body)['events'][0]['SUMMARY'])->toStartWith('Smith\\, J — Full groom\;');
});

it('counts a reschedule so a calendar client updates rather than duplicates', function () {
    $salon = aSyncSalon();
    $booking = aSyncBooking($salon, $salon['staff'], '2026-03-10 09:30:00');

    expect($booking->fresh()->calendar_sequence)->toBe(0);

    app(TenantContext::class)->set($salon['tenant']);

    try {
        $booking->forceFill([
            'starts_at' => CarbonImmutable::parse('2026-03-11 09:30:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-03-11 10:30:00', 'Europe/London')->utc(),
        ])->save();
    } finally {
        app(TenantContext::class)->clear();
    }

    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_STAFF, $salon['staff']->id)
        ->token;

    $event = parseIcs($this->get(feedUrl($salon['tenant'], $token))->getContent())['events'][0];

    expect($booking->fresh()->calendar_sequence)->toBe(1)
        ->and($event['SEQUENCE'])->toBe('1')
        ->and($event['DTSTART'])->toBe('20260311T093000Z');
});

it('records when a client last pulled the feed', function () {
    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON);

    expect($token->last_pulled_at)->toBeNull();

    $this->get(feedUrl($salon['tenant'], $token->token))->assertOk();

    expect($token->fresh()->last_pulled_at)->not->toBeNull();

    $this->actingAs($salon['owner'])
        ->get(route('settings.calendar-sync'))
        ->assertInertia(fn ($page) => $page->where('salonFeed.lastPulledAt', fn (?string $when) => is_string($when)));
});

/*
|--------------------------------------------------------------------------
| What the public endpoint refuses
|--------------------------------------------------------------------------
*/

it('404s a slug that is not a salon', function () {
    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $this->get('/book/ical/no-such-salon/'.$token.'.ics')->assertNotFound();
});

it('404s a token that belongs to another salon', function () {
    $mine = aSyncSalon();
    $theirs = aSyncSalon();

    $token = app(CalendarFeedTokenService::class)
        ->ensure($theirs['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $this->get(feedUrl($mine['tenant'], $token))->assertNotFound();
    $this->get(feedUrl($theirs['tenant'], $token))->assertOk();
});

it('404s a token nobody was ever issued, and one of the wrong shape', function () {
    $salon = aSyncSalon();

    $this->get(feedUrl($salon['tenant'], Str::random((int) config('calendar_sync.token_length'))))
        ->assertNotFound();

    $this->get(feedUrl($salon['tenant'], 'short'))->assertNotFound();
    $this->get('/book/ical/'.$salon['tenant']->slug.'/not_a_token.ics')->assertNotFound();
});

it('404s a feed on a salon that has been closed', function () {
    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $url = feedUrl($salon['tenant'], $token);

    $this->get($url)->assertOk();

    $salon['tenant']->delete();

    $this->get($url)->assertNotFound();
});

it('410s a revoked token rather than pretending it never existed', function () {
    $salon = aSyncSalon();
    $tokens = app(CalendarFeedTokenService::class);

    $old = $tokens->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)->token;
    $tokens->regenerate($salon['tenant'], CalendarFeedToken::SCOPE_SALON);

    $this->get(feedUrl($salon['tenant'], $old))
        ->assertStatus(410)
        ->assertSee('This calendar link has been regenerated and is no longer valid.', false);
});

it('429s a client that polls far harder than any calendar app does', function () {
    Config::set('calendar_sync.rate_limit_per_minute', 2);

    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $url = feedUrl($salon['tenant'], $token);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    $limited = $this->get($url)->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and($limited->headers->get('Content-Type'))->toContain('text/plain')
        ->and($limited->getContent())->toBe('Too many requests for this calendar feed. Try again shortly.');
});

it('asks a shared cache not to keep the file and a crawler not to index it', function () {
    $salon = aSyncSalon();
    $token = app(CalendarFeedTokenService::class)
        ->ensure($salon['tenant'], CalendarFeedToken::SCOPE_SALON)
        ->token;

    $response = $this->get(feedUrl($salon['tenant'], $token))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});
