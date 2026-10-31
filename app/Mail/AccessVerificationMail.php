<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccessVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $verificationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your AquaTrack email',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.access-verification',
        );
    }
}
