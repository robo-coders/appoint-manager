<?php

namespace App\Services\Notifications;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\SendBookingReminder;
use App\Jobs\SendSms;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingReminderMail;
use App\Mail\BookingRescheduledMail;
use App\Mail\DailyAgendaMail;
use App\Mail\RebookDueMail;
use App\Mail\SalonCancellationMail;
use App\Mail\SalonNewBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SmsAllowance;
use App\Services\Sms\SmsGateway;
use App\Support\TenantContext;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class Notifier
{
    public function __construct(private SmsGateway $sms) {}

    public function bookingConfirmed(Booking $booking): void
    {
        [$booking, $tenant] = $this->hydrate($booking);

        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $url = book_url(null, 'b/'.$booking->public_token);
        $sms = $this->fitSms($tenant->name.': confirmed '.$when.'. '.$url);

        $this->emailCustomer($tenant, $booking, $booking->customer, new BookingConfirmedMail($booking, $tenant), MessageType::BookingConfirmed, 'Your booking is confirmed.');
        $this->smsCustomer($tenant, $booking, $booking->customer, $sms, MessageType::BookingConfirmed);

        if ($tenant->email) {
            $this->emailSalon($tenant, $booking, new SalonNewBookingMail($booking, $tenant), MessageType::SalonNewBooking);
        }

        $this->scheduleReminder($booking);
    }

    public function bookingCancelled(Booking $booking, string $refundStatus): void
    {
        [$booking, $tenant] = $this->hydrate($booking);
        $when = $booking->starts_at->timezone($tenant->timezone)->format('j M H:i');
        $sms = $this->fitSms($tenant->name.': cancelled '.$when.'. Refund: '.$refundStatus);

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
        $sms = $this->fitSms($tenant->name.': new time '.$when.'. '.$url);

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
        $sms = $this->fitSms($tenant->name.': reminder '.$when.'. '.$url);

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
        $sms = $this->fitSms($tenant->name.': a slot is free. Claim: '.$claimUrl);
        $this->smsCustomer($tenant, null, $customer, $sms, MessageType::WaitlistOffer);
    }

    public function waitlistGone(Tenant $tenant, Customer $customer): void
    {
        $sms = $this->fitSms($tenant->name.': that slot was taken. We will text if another opens.');
        $this->smsCustomer($tenant, null, $customer, $sms, MessageType::WaitlistGone);
    }

    public function rebookDue(Tenant $tenant, Customer $customer, Subject $subject, string $body): void
    {
        $this->emailCustomer($tenant, null, $customer, new RebookDueMail($tenant, $subject, $body), MessageType::RebookDue, $body);
        $this->smsCustomer($tenant, null, $customer, $this->fitSms($body), MessageType::RebookDue);
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

    private function emailCustomer(Tenant $tenant, ?Booking $booking, Customer $customer, Mailable $mail, MessageType $type, string $body): void
    {
        Mail::to($customer->email)->queue($mail);
        $this->log($tenant, $customer, $booking, MessageChannel::Email, $type, $customer->email, $body, MessageStatus::Sent, null);
    }

    private function emailSalon(Tenant $tenant, Booking $booking, Mailable $mail, MessageType $type): void
    {
        Mail::to($tenant->email)->queue($mail);
        $this->log($tenant, null, $booking, MessageChannel::Email, $type, (string) $tenant->email, $type->value, MessageStatus::Sent, null);
    }

    /**
     * Records the message, then hands delivery to a queued job.
     *
     * Nothing here talks to Twilio inline. A provider outage must not be able to
     * roll back the booking or the refund that caused the message.
     */
    private function smsCustomer(Tenant $tenant, ?Booking $booking, Customer $customer, string $body, MessageType $type): void
    {
        if (! $this->smsEnabled($tenant) || ! $customer->phone) {
            return;
        }

        if (! app(SmsAllowance::class)->canSend($tenant)) {
            return;
        }

        $message = $this->log(
            $tenant, $customer, $booking, MessageChannel::Sms, $type,
            $customer->phone, $body, MessageStatus::Queued, null,
        );

        SendSms::dispatch($message->id);
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
    ): Message {
        $message = new Message;
        $message->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer?->id,
            'booking_id' => $booking?->id,
            'channel' => $channel,
            'type' => $type,
            'to' => $to,
            'body' => $body,
            'provider_id' => $providerId,
            'status' => $status,
        ]);
        $message->save();

        return $message;
    }

    private function fitSms(string $body): string
    {
        return Str::limit($body, 160, '');
    }
}
