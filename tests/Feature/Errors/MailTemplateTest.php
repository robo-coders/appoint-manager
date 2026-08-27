<?php

use App\Enums\BookingStatus;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingReminderMail;
use App\Mail\BookingRescheduledMail;
use App\Mail\DailyAgendaMail;
use App\Mail\SalonCancellationMail;
use App\Mail\SalonNewBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DesignTokens;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

/**
 * The seven transactional emails.
 *
 * They were stock `<x-mail::message>` markdown — a framework default with the
 * framework's own chrome, which is the first thing a customer sees after
 * booking. These assert the things that are actually easy to get wrong in
 * email and impossible to notice until somebody complains: that a plain text
 * part exists at all, that it is not HTML-escaped, that the layout survives a
 * client with no stylesheet, and that money and times keep the mono tabular
 * treatment they have everywhere else in the product.
 */

/** A booking with everything filled in: staff, address, price and a deposit. */
function aMailBooking(): array
{
    $tenant = Tenant::factory()->create([
        // An ampersand on purpose: it is what broke the plain text part.
        'name' => 'Paw & Order',
        'timezone' => 'Europe/London',
        'address_line_1' => '12 Willow Street',
        'city' => 'London',
        'postcode' => 'E8 3AA',
    ]);

    app(TenantContext::class)->set($tenant);

    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Rosa Adeyemi']);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom — medium dog',
        'price' => 4500,
        'deposit_amount' => 1000,
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Naomi Ellery']);

    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 09:30', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-09-03 11:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => 4500,
        'deposit_at_booking' => 1000,
    ]);

    /*
     * Relations attached while the context is still set. `TenantScope` fails
     * closed — with no context it appends `0 = 1` rather than reading across
     * tenants — so a lazy load after `clear()` returns null and the mailable
     * dies on `$booking->service->name`. Which is correct scoping and a
     * confusing test failure.
     */
    $booking->setRelation('service', $service)
        ->setRelation('staff', $staff)
        ->setRelation('customer', $customer);

    app(TenantContext::class)->clear();

    return [$booking, $tenant];
}

/**
 * The data a Mailable's views are rendered with.
 *
 * `buildViewData()` returns the Mailable's public properties and whatever
 * `with()` put on the *instance* — it does **not** include the `with:` array on
 * the `Content` object, which is where all of this copy lives. Merging both is
 * what the mailer does internally at render time, and getting it wrong made
 * every text-part assertion fail on "Undefined variable $heading".
 *
 * @return array<string, mixed>
 */
function viewDataFor(Mailable $mail): array
{
    return array_merge($mail->buildViewData(), $mail->content()->with);
}

/** @return array<string, Mailable> */
function everyMail(): array
{
    [$booking, $tenant] = aMailBooking();

    return [
        'booking-confirmed' => new BookingConfirmedMail($booking, $tenant),
        'booking-reminder' => new BookingReminderMail($booking, $tenant),
        'booking-rescheduled' => new BookingRescheduledMail($booking, $tenant),
        'booking-cancelled' => new BookingCancelledMail($booking, $tenant, '£10.00 back within five working days'),
        'salon-new-booking' => new SalonNewBookingMail($booking, $tenant),
        'salon-cancellation' => new SalonCancellationMail($booking, $tenant),
        'daily-agenda' => new DailyAgendaMail($tenant, collect([$booking])),
    ];
}

/*
|--------------------------------------------------------------------------
| Every message has both parts
|--------------------------------------------------------------------------
|
| The plaintext part is not an afterthought. Somebody reads it — a phone on a
| bad signal, a client set to text-only, a screen reader that prefers it — and
| every spam filter scores a message that has none.
|
*/

it('sends both an HTML and a plain text part', function () {
    foreach (everyMail() as $name => $mail) {
        $content = $mail->content();

        expect($content->view)->not->toBeNull($name.' has no HTML part')
            ->and($content->text)->not->toBeNull($name.' has no plain text part');

        expect(view()->exists($content->text))->toBeTrue($name.'’s text view is missing');
    }
});

it('does not HTML-escape the plain text part', function () {
    /*
     * Blade escapes by default, which is right in HTML and wrong here: there is
     * no markup in a text part for a value to break out of, and a salon called
     * "Paw & Order" arrived in a text-only client as "Paw &amp; Order".
     */
    foreach (everyMail() as $name => $mail) {
        $text = view($mail->content()->text, viewDataFor($mail))->render();

        expect($text)->not->toContain('&amp;')
            ->and($text)->not->toContain('&#039;')
            ->and($text)->not->toContain('&quot;');
    }
});

it('names the salon in the plain text part, ampersand and all', function () {
    [$booking, $tenant] = aMailBooking();
    $mail = new BookingConfirmedMail($booking, $tenant);

    expect(view($mail->content()->text, viewDataFor($mail))->render())
        ->toContain('Paw & Order');
});

/*
|--------------------------------------------------------------------------
| The HTML survives a client that throws the stylesheet away
|--------------------------------------------------------------------------
|
| Outlook on Windows renders with Word's engine: no flexbox, no grid, no
| `max-width` on a div, and `<style>` largely discarded. Every structural rule
| has to be inline on a table.
|
*/

it('lays out with tables and inline styles, not with a stylesheet', function () {
    foreach (everyMail() as $name => $mail) {
        $html = $mail->render();

        expect($html)
            ->toContain('<table role="presentation"')
            // Nothing structural in the <style> block: the layout must hold up
            // with it thrown away.
            ->not->toContain('display:flex')
            ->not->toContain('display:grid')
            ->not->toContain('<link ');
    }
});

it('tells the client we have an opinion about dark mode', function () {
    /*
     * Without `color-scheme`, iOS Mail and Outlook force-invert the whole
     * message — and an auto-inverted warm-paper email comes back muddy
     * blue-grey with the ink action flipped to near-white on near-white.
     * DESIGN.md's light-only rule is about the app; email is repainted whether
     * or not we have a view, so specifying beats being repainted.
     */
    $html = (new BookingConfirmedMail(...aMailBooking()))->render();

    expect($html)
        ->toContain('name="color-scheme"')
        ->toContain('prefers-color-scheme: dark')
        /*
         * And the action's *label* is overridden, not only the cell it sits in.
         * The anchor carries an inline colour, which beats a class on its
         * parent — so without this the button inverts its fill and keeps its
         * white text, and the only action in the message disappears.
         */
        ->toContain('.action-label');
});

it('keeps money and times mono and tabular, as everywhere else', function () {
    $html = (new BookingConfirmedMail(...aMailBooking()))->render();

    expect($html)
        ->toContain('font-variant-numeric:tabular-nums')
        ->toContain('£45.00')
        ->toContain('£10.00')
        // The remainder, computed rather than left for the reader to work out.
        ->toContain('£35.00');
});

it('writes its own preheader rather than leaking the first line of the body', function () {
    foreach (everyMail() as $name => $mail) {
        expect(viewDataFor($mail)['preheader'] ?? null)
            ->not->toBeNull($name.' has no preheader')
            ->not->toBe('');
    }
});

/*
 * A deposit row for a salon that takes no deposit would read "Deposit £0.00",
 * which invites exactly the question it exists to answer.
 */
it('omits the deposit rows when there is no deposit', function () {
    [$booking, $tenant] = aMailBooking();
    $booking->deposit_at_booking = new Money(0);

    $html = (new BookingConfirmedMail($booking, $tenant))->render();

    expect($html)->not->toContain('Deposit paid')
        ->and($html)->not->toContain('Due on the day');
});

it('states an empty day rather than sending a blank agenda', function () {
    [, $tenant] = aMailBooking();

    $mail = new DailyAgendaMail($tenant, collect());

    expect($mail->render())->toContain('Nothing booked tomorrow')
        ->and(view($mail->content()->text, viewDataFor($mail))->render())
        ->toContain('Nothing booked tomorrow');
});

it('reads the palette from tokens.css rather than a second copy', function () {
    $html = (new BookingConfirmedMail(...aMailBooking()))->render();

    expect($html)->toContain(DesignTokens::value('ink'))
        ->and($html)->toContain(DesignTokens::value('paper'));
});
