<?php

namespace App\Mail;

use App\Support\MailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MarketingEnquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $enquirerName,
        public string $business,
        public string $email,
        public ?string $phone,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Enquiry — '.$this->business,
            replyTo: [new Address($this->email, $this->enquirerName)],
        );
    }

    public function content(): Content
    {
        $rows = [
            ['label' => 'Name', 'value' => $this->enquirerName, 'mono' => false],
            ['label' => 'Business', 'value' => $this->business, 'mono' => false],
            ['label' => 'Email', 'value' => $this->email, 'mono' => true],
            ['label' => 'Phone', 'value' => $this->phone ?? 'Not given', 'mono' => true],
        ];

        return new Content(
            view: 'mail.marketing-enquiry',
            text: 'mail.text.marketing-enquiry',
            with: [
                'subject' => 'Enquiry — '.$this->business,
                'preheader' => $this->enquirerName.' — '.$this->business,
                'heading' => 'Someone asked about it',
                'lede' => 'Reply to this email and it goes straight back to them.',
                'rows' => $rows,
                'rowsText' => MailCopy::asText($rows),
                'body' => $this->body,
                'footer' => config('product.name'),
            ],
        );
    }
}
