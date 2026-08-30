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

class SalonNewBookingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New online booking');
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
        $rows = MailCopy::bookingRows($this->booking, $this->tenant);
        array_unshift($rows, [
            'label' => 'Who',
            'value' => $this->booking->customer->name,
            'mono' => false,
        ]);

        return new Content(
            view: 'mail.salon-new-booking',
            text: 'mail.text.salon-new-booking',
            with: [
                'subject' => 'New booking — '.MailCopy::when($this->booking, $this->tenant),
                'preheader' => $this->booking->customer->name.' — '.$this->booking->service->name,
                'heading' => 'Somebody booked online',
                'lede' => 'It is already on the diary. Nothing to confirm.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'diaryUrl' => Surface::App->to('diary'),
                'footer' => config('product.name'),
            ],
        );
    }
}
