<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    Mail::fake();
});

function bookingFor(array $salon, CarbonImmutable $startsAt, BookingStatus $status = BookingStatus::Confirmed): Booking
{
    return Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => Customer::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => $status,
        'source' => BookingSource::Online,
    ]);
}

it('will not let the database cascade a staff delete over their bookings', function () {
    $salon = aSalon();
    $booking = bookingFor($salon, CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc());

    expect(fn () => User::withoutGlobalScopes()->whereKey($salon['staff']->id)->delete())
        ->toThrow(QueryException::class);

    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
});

it('refuses to delete your own account while you have future bookings', function () {
    $salon = aSalon(['staff' => ['role' => UserRole::Owner, 'password' => bcrypt('password')]]);
    $booking = bookingFor($salon, CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc());

    actingAsTenant($salon['staff'])
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(User::withoutGlobalScopes()->whereKey($salon['staff']->id)->exists())->toBeTrue()
        ->and(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
});

it('explains why the account cannot be deleted', function () {
    $salon = aSalon(['staff' => ['role' => UserRole::Owner, 'password' => bcrypt('password')]]);
    bookingFor($salon, CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc());

    $response = actingAsTenant($salon['staff'])
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);

    expect(session('errors')->first('password'))->toContain('upcoming');
});

it('erases the person but keeps past bookings readable when the account is closed', function () {
    $salon = aSalon(['staff' => ['role' => UserRole::Owner, 'password' => bcrypt('password')]]);
    User::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);
    $past = bookingFor($salon, CarbonImmutable::parse('2026-02-10 09:00:00', 'Europe/London')->utc(), BookingStatus::Completed);

    actingAsTenant($salon['staff'])
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    $closed = User::withoutGlobalScopes()->whereKey($salon['staff']->id)->first();

    expect($closed)->not->toBeNull()
        ->and($closed->name)->toBe('Former team member')
        ->and($closed->email)->not->toContain('@example')
        ->and($closed->is_active)->toBeFalse()
        ->and(Booking::withoutGlobalScopes()->whereKey($past->id)->exists())->toBeTrue();
});

it('refuses to close the last owner account', function () {
    $salon = aSalon(['staff' => ['role' => UserRole::Owner, 'password' => bcrypt('password')]]);

    actingAsTenant($salon['staff'])
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($salon['staff']->fresh()->name)->not->toBe('Former team member');
});

it('will not let a closed account log back in', function () {
    $salon = aSalon(['staff' => ['role' => UserRole::Owner, 'password' => bcrypt('password')]]);
    User::factory()->create(['tenant_id' => $salon['tenant']->id, 'role' => UserRole::Owner]);
    $email = $salon['staff']->email;

    actingAsTenant($salon['staff'])->delete(route('profile.destroy'), ['password' => 'password']);
    auth()->logout();

    $this->post(route('login'), ['email' => $email, 'password' => 'password'])
        ->assertSessionHasErrors();
    $this->assertGuest();
});
