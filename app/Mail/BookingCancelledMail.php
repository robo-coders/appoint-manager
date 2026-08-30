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

class BookingCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public Tenant $tenant,
        public string $refundStatus,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->tenant->name.' booking was cancelled');
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
        /*
         * The refund is the fact this message exists to carry, so it is a row
         * of its own rather than a sentence somebody has to find.
         */
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);
        $rows[] = ['label' => 'Refund', 'value' => $this->refundStatus, 'mono' => false];

        return new Content(
            view: 'mail.booking-cancelled',
            text: 'mail.text.booking-cancelled',
            with: [
                'subject' => 'Your '.$this->tenant->name.' appointment is cancelled',
                'preheader' => 'Was '.MailCopy::when($this->booking, $this->tenant),
                'heading' => 'That appointment is cancelled',
                'lede' => 'It is off the diary. Booking again is the same as the first time.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
            ],
        );
    }
}
