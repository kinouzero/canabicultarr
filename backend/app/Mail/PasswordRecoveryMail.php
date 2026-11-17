<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordRecoveryMail extends Mailable
{
    public function __construct(public readonly string $resetUrl, public readonly int $minutes, string $language)
    {
        $this->locale($language);
    }

    public function build(): static
    {
        return $this->subject(__('password-recovery.subject'))
            ->view('mail.account-message', ['copy' => 'password-recovery', 'actionUrl' => $this->resetUrl, 'details' => __('password-recovery.effects')])
            ->text('mail.text.password-recovery', ['resetUrl' => $this->resetUrl, 'minutes' => $this->minutes]);
    }
}
