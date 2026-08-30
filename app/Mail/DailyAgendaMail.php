<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Tenant;
use App\Support\MailCopy;
use App\Support\Surface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class DailyAgendaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function __construct(public Tenant $tenant, public Collection $bookings) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tomorrow at '.$this->tenant->name);
    }

    /**
     * Every string on the page, built here.
     *
     * The copy is in PHP because that is the standing rule for anything a
     * customer reads, and because each of these messages has to be written
     * twice — once in HTML and once in plain text. Two templates composing the
     * same sentence is two sentences that will eventually disagree.
     *
     * The plaintext part is not an afterthought. Somebody reads it: a phone on a
     * bad signal, a client set to text-only, a screen reader that prefers it,
     * and every spam filter that scores a message carrying no text alternative.
     */
    public function content(): Content
    {
        $agenda = $this->bookings
            ->map(fn (Booking $booking) => [
                'time' => MailCopy::time($booking, $this->tenant),
                'who' => $booking->customer->name,
                'what' => $booking->service->name,
            ])
            ->values()
            ->all();

        $count = count($agenda);

        return new Content(
            view: 'mail.daily-agenda',
            text: 'mail.text.daily-agenda',
            with: [
                'subject' => 'Tomorrow at '.$this->tenant->name,
                'preheader' => $count === 0
                    ? 'Nothing booked tomorrow.'
                    : $count.' '.($count === 1 ? 'appointment' : 'appointments').' tomorrow.',
                'heading' => 'Tomorrow',
                'lede' => $count === 0
                    ? null
                    : $count.' '.($count === 1 ? 'appointment' : 'appointments').', starting at '.$agenda[0]['time'].'.',
                'agenda' => $agenda,
                // A stated empty state, not a blank space. Nothing booked is a
                // fact worth sending; an empty email is a bug.
                'emptyLine' => 'Nothing booked tomorrow.',
                'diaryUrl' => Surface::App->to('diary'),
                'footer' => config('product.name'),
            ],
        );
    }
}
