<?php

namespace App\Sandbox;

use App\Exceptions\BookingNotCompletableException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToolkitController extends Controller
{
    public function jump(Request $request, DateJump $jump): RedirectResponse
    {
        $tenant = ActingTenant::from($request);
        $date = (string) $request->input('date');

        try {
            $result = $jump->run($tenant, $date);
        } catch (SandboxRefusal $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        SandboxState::remember($tenant->fresh(), 'Jumped to '.$result['date']);

        return $this->said($this->moved($result, 'to '.$result['date']));
    }

    public function noShow(Request $request, NoShowSimulator $simulator): RedirectResponse
    {
        $tenant = ActingTenant::from($request);
        $bookingId = $request->filled('booking_id') ? (int) $request->input('booking_id') : null;

        try {
            $booking = $simulator->mark($tenant, $bookingId, $request->user());
        } catch (SandboxRefusal $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        } catch (BookingNotCompletableException $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        $name = Customer::withoutGlobalScopes()->find($booking->customer_id)?->name ?? 'the customer';
        SandboxState::remember($tenant->fresh(), 'Marked '.$name.' as a no-show');

        return $this->said('Marked as a no-show. Loyalty and the waitlist ran the same way they would in a real shop.');
    }

    public function waitlistFree(Request $request, WaitlistSimulator $simulator): RedirectResponse
    {
        $tenant = ActingTenant::from($request);

        try {
            $result = $simulator->freeSlot($tenant);
        } catch (SandboxRefusal $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        SandboxState::remember($tenant->fresh(), 'Freed a slot for the waitlist');

        $offers = $result['offered'];
        $tail = $offers === 0
            ? 'Nobody was waiting for that service.'
            : $offers.' '.($offers === 1 ? 'person was' : 'people were').' offered the hour.';

        return $this->said('The appointment was cancelled. '.$tail);
    }

    public function waitlistExpire(Request $request, WaitlistSimulator $simulator): RedirectResponse
    {
        $tenant = ActingTenant::from($request);

        try {
            $result = $simulator->expireOffer($tenant);
        } catch (SandboxRefusal $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        SandboxState::remember($tenant->fresh(), 'Expired a waitlist offer');

        $next = $result['offered'];
        $tail = $next === 0
            ? 'Nobody else was waiting.'
            : 'It rolled on to the next person.';

        return $this->said('The current offer ran out. '.$tail);
    }

    public function remind(Request $request, ReminderTrigger $reminders): RedirectResponse
    {
        $tenant = ActingTenant::from($request);
        $sent = $reminders->run($tenant);
        SandboxState::remember($tenant->fresh(), 'Sent due reminders');

        if ($sent === 0) {
            return $this->said('Nothing was due to remind. Skip time or jump to a date first.');
        }

        return $this->said($sent.' '.($sent === 1 ? 'reminder' : 'reminders').' went out to the outbox.');
    }

    public function clearOutbox(Request $request): RedirectResponse
    {
        $tenant = ActingTenant::from($request);
        SmsOutbox::clear($tenant);
        SandboxState::remember($tenant->fresh(), 'Cleared the SMS outbox');

        return $this->said('SMS outbox cleared. Appointments and customers are untouched.');
    }

    public function flaky(Request $request): RedirectResponse
    {
        $tenant = ActingTenant::from($request);
        $on = $request->boolean('enabled');
        SandboxState::put($tenant, ['flaky_network' => $on]);
        SandboxState::remember($tenant->fresh(), $on ? 'Turned on flaky network' : 'Turned off flaky network');

        return $this->said($on
            ? 'Flaky network is on. Diary booking and cancel may stall or fail. The public booking page is not affected.'
            : 'Flaky network is off. Diary actions run normally again.');
    }

    /**
     * @param  array{shifted: int, released: int, declined: int, offers: int, reminders: int}  $result
     */
    private function moved(array $result, string $where): string
    {
        $message = 'Your shop moved forward '.$where.'.';
        $happened = [];

        if ($result['reminders'] > 0) {
            $happened[] = $result['reminders'].' '.($result['reminders'] === 1 ? 'reminder' : 'reminders').' went out';
        }

        if ($result['released'] > 0) {
            $happened[] = $result['released'].' unpaid '.($result['released'] === 1 ? 'hold' : 'holds').' released';
        }

        if ($result['declined'] > 0) {
            $happened[] = $result['declined'].' '.($result['declined'] === 1 ? 'request' : 'requests').' expired';
        }

        if ($result['offers'] > 0) {
            $happened[] = $result['offers'].' waitlist '.($result['offers'] === 1 ? 'offer' : 'offers').' ran out';
        }

        if ($happened === []) {
            return $message.' Nothing was waiting to happen.';
        }

        return $message.' '.ucfirst(implode(', ', $happened)).'.';
    }

    private function said(string $message): RedirectResponse
    {
        return back()->with('toast', $message);
    }
}
