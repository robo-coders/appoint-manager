<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Tenant;
use App\Support\MailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->tenant->name.' booking is confirmed');
    }

    public function content(): Content
    {
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);

        return new Content(
            view: 'mail.booking-confirmed',
            text: 'mail.text.booking-confirmed',
            with: [
                'subject' => 'Your '.$this->tenant->name.' booking is confirmed',
                'preheader' => MailCopy::when($this->booking, $this->tenant).' — '.$this->booking->service->name,
                'heading' => 'You are booked in',
                'lede' => $this->tenant->name.' has your appointment. Nothing else is needed before the day.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'manageUrl' => book_url(null, 'b/'.$this->booking->public_token),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
            ],
        );
    }
}
