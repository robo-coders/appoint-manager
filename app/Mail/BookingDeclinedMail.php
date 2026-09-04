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

class BookingDeclinedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public Tenant $tenant,
        public ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->tenant->name.' request');
    }

    public function content(): Content
    {
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);
        $lede = $this->reason
            ? $this->reason
            : $this->tenant->name.' cannot take that time. Nothing has been charged.';

        return new Content(
            view: 'mail.booking-declined',
            text: 'mail.text.booking-declined',
            with: [
                'subject' => $this->tenant->name.' could not confirm that time',
                'preheader' => 'Was '.MailCopy::when($this->booking, $this->tenant),
                'heading' => 'That time is not available',
                'lede' => $lede,
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
            ],
        );
    }
}
