<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactUsNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $data
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Contact Form] ' . $this->data['subject'],
            replyTo: [$this->data['email']],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-us',
        );
    }
}