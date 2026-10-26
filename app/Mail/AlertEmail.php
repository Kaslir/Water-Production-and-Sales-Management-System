<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AlertEmail extends Mailable
{
    public function __construct(public string $alertTitle, public string $alertMessage) {}

    public function build(): self
    {
        return $this->subject('AquaTrack alert: '.$this->alertTitle)->text('mail.alert');
    }
}
