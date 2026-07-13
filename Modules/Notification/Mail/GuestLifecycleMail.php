<?php

namespace Modules\Notification\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GuestLifecycleMail extends Mailable
{
    public function __construct(
        public readonly string $messageSubject,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageSubject);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<div style="font-family: sans-serif; white-space: pre-line">'
                .e($this->messageBody)
                .'</div>',
        );
    }
}
