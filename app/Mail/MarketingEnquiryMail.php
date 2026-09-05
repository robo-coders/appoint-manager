<?php

namespace App\Mail;

use App\Support\MailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An enquiry from the marketing contact form.
 *
 * **This is the one line that used to be missing.** `sendContact()` validated,
 * throttled and honeypotted the form and then wrote the enquiry to
 * `storage/logs` — which meant the page's own copy had to admit that nothing
 * reached a person. The log line stays (it is the record that a submission
 * happened even if mail fails), and this carries it to
 * `config('billing.owner_alert_email')`.
 *
 * Two details that are not decoration:
 *
 *   - **`replyTo` is the enquirer, `from` is not.** Sending as the visitor's own
 *     address is how a marketing form gets the domain's mail rejected outright:
 *     it fails SPF and DKIM for whatever domain they typed. The envelope is
 *     ours; replying goes to them.
 *
 *   - **The message body is passed as a value, never as markup.** It is the one
 *     string on this surface that a stranger wrote, and it is rendered by Blade
 *     escaped in the HTML part and printed as-is in the text part, which is what
 *     a text part is.
 */
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
