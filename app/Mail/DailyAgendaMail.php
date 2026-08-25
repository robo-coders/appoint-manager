<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Tenant;
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

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-agenda');
    }
}
