<?php

namespace App\Services\Notifications;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\SendBookingReminder;
use App\Jobs\SendSms;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingDeclinedMail;
use App\Mail\BookingReminderMail;
use App\Mail\BookingRescheduledMail;
use App\Mail\DailyAgendaMail;
use App\Mail\RebookDueMail;
use App\Mail\SalonCancellationMail;
use App\Mail\SalonNewBookingMail;
use App\Mail\SalonNewRequestMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SmsAllowance;
use App\Services\Loyalty\Loyalty;
use App\Services\Sms\SmsConsent;
use App\Services\Sms\SmsGateway;
use App\Support\PhoneNumber;
use App\Support\SmsSegments;
use App\Support\Surface;
use App\Support\TenantContext;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

final class Notifier
{
    public function __construct(private SmsGateway $sms) {}

    public function bookingConfirmed(Booking $booking): void
    {
        [$booking, $tenant] = $this->hydrate($booking);

        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $url = book_url(null, 'b/'.$booking->public_token);

        /*
         * The loyalty stamps, on the confirmation.
         *
         * There is no customer portal, so this message and the owner's customer
         * screen are the only two places the count is ever visible — which makes
         * this the feature's whole customer-facing surface rather than a nicety.
         * Null for every tenant that has the feature off, and for a customer who
         * is not enrolled, so the message is byte-identical to what it was.
         *
         * Inside `fitSms`, so the progress line competes with the salon's name
         * for the segment budget and the *name* is what gives way — never the
         * link, and never the date. See `SmsSegments::fit`.
         */
        $progress = app(Loyalty::class)->progressLine($booking);

        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': confirmed '.$when.'. '.$url
            .($progress === null ? '' : ' '.$progress));

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingConfirmedMail($booking, $tenant), MessageType::BookingConfirmed, 'Your booking is confirmed.');
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::BookingConfirmed);

        if ($tenant->email) {
            $this->emailSalon($tenant, $booking, new SalonNewBookingMail($booking, $tenant), MessageType::SalonNewBooking);
        }

        $this->scheduleReminder($booking);
    }

    public function bookingRequested(Booking $booking): void
    {
        [$booking, $tenant] = $this->hydrate($booking);

        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $url = Surface::App->to('dashboard');
        $who = $booking->customer?->name ?? 'A customer';
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': request '.$when.' — '.$who.'. '.$url);

        if ($tenant->email) {
            $this->emailSalon($tenant, $booking, new SalonNewRequestMail($booking, $tenant), MessageType::SalonNewRequest);
        }

        $this->smsTenant($tenant, $booking, $sms, MessageType::BookingRequested);
    }

    public function bookingDeclined(Booking $booking, ?string $reason = null): void
    {
        [$booking, $tenant] = $this->hydrate($booking);
        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $sms = $this->fitSms($tenant->name, function (string $salon) use ($when, $reason) {
            $body = $salon.': that time is not available ('.$when.').';

            if (filled($reason)) {
                $body .= ' '.$reason;
            }

            return $body;
        });

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingDeclinedMail($booking, $tenant, $reason), MessageType::BookingDeclined, 'That time is not available.');
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::BookingDeclined);
    }

    public function bookingCancelled(Booking $booking, string $refundStatus): void
    {
        [$booking, $tenant] = $this->hydrate($booking);
        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': cancelled '.$when.'. Refund: '.$refundStatus);

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingCancelledMail($booking, $tenant, $refundStatus), MessageType::Cancelled, 'Cancelled. Refund: '.$refundStatus);
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::Cancelled);

        if ($tenant->email) {
            $this->emailSalon($tenant, $booking, new SalonCancellationMail($booking, $tenant), MessageType::SalonCancellation);
        }
    }

    public function bookingRescheduled(Booking $booking): void
    {
        [$booking, $tenant] = $this->hydrate($booking);
        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $url = book_url(null, 'b/'.$booking->public_token);
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': new time '.$when.'. '.$url);

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingRescheduledMail($booking, $tenant), MessageType::Rescheduled, 'Your booking was moved.');
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::Rescheduled);

        // The reminder queued for the old time is retired by the reschedule; queue
        // a fresh one against the new time or the customer is reminded on the wrong day.
        $this->scheduleReminder($booking);
    }

    public function reminder(Booking $booking): void
    {
        [$booking, $tenant] = $this->hydrate($booking);
        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $url = book_url(null, 'b/'.$booking->public_token);
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': reminder '.$when.'. '.$url);

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingReminderMail($booking, $tenant), MessageType::Reminder, 'Reminder for your booking.');
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::Reminder);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function dailyAgenda(Tenant $tenant, Collection $bookings): void
    {
        if (! $tenant->email) {
            return;
        }

        Mail::to($tenant->email)->queue(new DailyAgendaMail($tenant, $bookings));
        $this->log($tenant, null, null, MessageChannel::Email, MessageType::DailyAgenda, $tenant->email, 'Daily agenda', MessageStatus::Sent, null);
    }

    public function waitlistOffer(Tenant $tenant, Customer $customer, string $claimUrl): void
    {
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': a slot is free. Claim: '.$claimUrl);
        $this->smsCustomer($tenant, null, $customer, $sms, MessageType::WaitlistOffer);
    }

    public function waitlistGone(Tenant $tenant, Customer $customer): void
    {
        $sms = $this->fitSms($tenant->name, fn (string $salon) => $salon.': that slot was taken. We will text if another opens.');
        $this->smsCustomer($tenant, null, $customer, $sms, MessageType::WaitlistGone);
    }

    /**
     * @return Message|null The queued SMS, so the caller can tie a rebooking
     *                      claim to it and hear about a later failure.
     */
    public function rebookDue(Tenant $tenant, Customer $customer, Subject $subject, string $body): ?Message
    {
        // The body arrives already composed and already carrying its opt-out
        // notice, because the dry run showed the operator that exact string and
        // reshaping it here would make the preview a lie.
        $this->emailCustomer($tenant, null, $customer, new RebookDueMail($tenant, $subject, $body), MessageType::RebookDue, $body, $subject);

        return $this->smsCustomer($tenant, null, $customer, $body, MessageType::RebookDue, $subject);
    }

    private function scheduleReminder(Booking $booking): void
    {
        $when = $booking->starts_at->utc()->subHours((int) config('booking.reminder_hours'));

        if ($when->isFuture()) {
            SendBookingReminder::dispatch($booking->id)->delay($when);
        }
    }

    /**
     * @return array{0: Booking, 1: Tenant}
     */
    private function hydrate(Booking $booking): array
    {
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $booking->setRelation('tenant', $tenant);
        $booking->setRelation('customer', Customer::withoutGlobalScopes()->find($booking->customer_id));
        $booking->setRelation('service', Service::withoutGlobalScopes()->find($booking->service_id));
        $booking->setRelation('staff', User::withoutGlobalScopes()->find($booking->staff_id));

        return [$booking, $tenant];
    }

    private function smsEnabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'notifications.sms_enabled', true);
    }

    private function emailCustomer(Tenant $tenant, ?Booking $booking, Customer $customer, Mailable $mail, MessageType $type, string $body, ?Subject $subject = null): void
    {
        if (! $customer->email) {
            return;
        }

        Mail::to($customer->email)->queue($mail);
        $this->log($tenant, $customer, $booking, MessageChannel::Email, $type, $customer->email, $body, MessageStatus::Sent, null, 1, $subject);
    }

    private function emailSalon(Tenant $tenant, Booking $booking, Mailable $mail, MessageType $type): void
    {
        Mail::to($tenant->email)->queue($mail);
        $this->log($tenant, null, $booking, MessageChannel::Email, $type, (string) $tenant->email, $type->value, MessageStatus::Sent, null);
    }

    private function smsTenant(Tenant $tenant, Booking $booking, string $body, MessageType $type): void
    {
        if (! $this->smsEnabled($tenant) || ! filled($tenant->phone)) {
            return;
        }

        try {
            $to = PhoneNumber::toE164((string) $tenant->phone, $tenant->country ?? 'GB');
        } catch (InvalidArgumentException) {
            return;
        }

        $body = SmsSegments::sanitise($body);
        $segments = SmsSegments::count($body);

        if (! app(SmsAllowance::class)->canSend($tenant, $segments)) {
            return;
        }

        $message = $this->log(
            $tenant, null, $booking, MessageChannel::Sms, $type,
            $to, $body, MessageStatus::Queued, null, $segments,
        );

        SendSms::dispatch($message->id);
    }

    /**
     * Records the message, then hands delivery to a queued job.
     *
     * Nothing here talks to Twilio inline. A provider outage must not be able to
     * roll back the booking or the refund that caused the message.
     */
    private function smsCustomer(Tenant $tenant, ?Booking $booking, Customer $customer, string $body, MessageType $type, ?Subject $subject = null): ?Message
    {
        if (! $this->smsEnabled($tenant) || ! $customer->phone) {
            return null;
        }

        // The opt-out gate, and the only place it lives. A customer who replied
        // STOP is suppressed from marketing and nothing else: a confirmation, a
        // reminder and a waitlist offer are about an appointment they made and
        // withholding them would put somebody outside a locked salon door.
        if ($type->isMarketing() && app(SmsConsent::class)->isOptedOut($customer)) {
            return null;
        }

        $body = SmsSegments::sanitise($body);
        $segments = SmsSegments::count($body);

        if (! app(SmsAllowance::class)->canSend($tenant, $segments)) {
            return null;
        }

        $message = $this->log(
            $tenant, $customer, $booking, MessageChannel::Sms, $type,
            $customer->phone, $body, MessageStatus::Queued, null, $segments, $subject,
        );

        SendSms::dispatch($message->id);

        return $message;
    }

    private function log(
        Tenant $tenant,
        ?Customer $customer,
        ?Booking $booking,
        MessageChannel $channel,
        MessageType $type,
        string $to,
        string $body,
        MessageStatus $status,
        ?string $providerId,
        int $segments = 1,
        ?Subject $subject = null,
    ): Message {
        $message = new Message;
        $message->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer?->id,
            'subject_id' => $subject?->id,
            'booking_id' => $booking?->id,
            'channel' => $channel,
            'type' => $type,
            'to' => $to,
            'body' => $body,
            'segments' => $segments,
            'provider_id' => $providerId,
            'status' => $status,
        ]);
        $message->save();

        return $message;
    }

    /**
     * Keep a transactional SMS inside the segment budget without cutting the
     * link off the end of it.
     *
     * The salon's name is the only unbounded string in any of these bodies, so
     * it is the one that gives way. See `SmsSegments::fit` for what this
     * replaced and why it mattered.
     *
     * @param  callable(string): string  $render
     */
    private function fitSms(string $salon, callable $render): string
    {
        return SmsSegments::fit($salon, $render, (int) config('rebooking.message.max_segments', 3));
    }
}
