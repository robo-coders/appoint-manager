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

class BookingRescheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->tenant->name.' booking was moved');
    }

    public function content(): Content
    {
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);

        return new Content(
            view: 'mail.booking-rescheduled',
            text: 'mail.text.booking-rescheduled',
            with: [
                'subject' => 'Your '.$this->tenant->name.' appointment moved',
                'preheader' => 'Now '.MailCopy::when($this->booking, $this->tenant),
                'heading' => 'Your appointment has moved',
                'lede' => 'This is the new time. Your deposit carries over — there is nothing more to pay now.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'manageUrl' => book_url(null, 'b/'.$this->booking->public_token),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
            ],
        );
    }
}
