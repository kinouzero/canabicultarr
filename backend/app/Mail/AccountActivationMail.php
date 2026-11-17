<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AccountActivationMail extends Mailable
{
    public function __construct(public readonly string $activationUrl, string $language)
    {
        $this->locale($language);
    }

    public function build(): static
    {
        return $this->subject(__('account-activation.subject'))
            ->view('mail.account-message', ['copy' => 'account-activation', 'actionUrl' => $this->activationUrl])
            ->text('mail.text.account-activation');
    }
}
