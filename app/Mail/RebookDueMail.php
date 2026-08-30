<?php

namespace App\Mail;

use App\Models\Subject;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RebookDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public Subject $dueSubject,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->dueSubject->name.' is due at '.$this->tenant->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.rebook-due',
            text: 'mail.text.rebook-due',
            with: [
                'heading' => $this->dueSubject->name.' is due',
                'lede' => $this->body,
                'bookUrl' => book_url($this->tenant->slug),
                'footer' => 'Sent by '.config('product.name').' on behalf of '.$this->tenant->name.'.',
                'tenant' => $this->tenant,
            ],
        );
    }
}
