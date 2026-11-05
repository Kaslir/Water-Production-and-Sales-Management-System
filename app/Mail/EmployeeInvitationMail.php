<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $employeeName, public string $roleName, public string $activationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've Been Invited to AquaTrack");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.employee-invitation');
    }
}
