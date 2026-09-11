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

class BookingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reminder: '.$this->tenant->name.' tomorrow');
    }

    public function content(): Content
    {
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);

        return new Content(
            view: 'mail.booking-reminder',
            text: 'mail.text.booking-reminder',
            with: [
                'subject' => 'Tomorrow at '.$this->tenant->name,
                'preheader' => MailCopy::when($this->booking, $this->tenant).' — '.$this->booking->service->name,
                'heading' => 'A reminder about tomorrow',
                'lede' => 'If anything has changed, there is time to move it or cancel it now.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'manageUrl' => book_url(null, 'b/'.$this->booking->public_token),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
            ],
        );
    }
}
