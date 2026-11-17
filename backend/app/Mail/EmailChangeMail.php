<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class EmailChangeMail extends Mailable
{
    public function __construct(public readonly string $confirmationUrl, string $language)
    {
        $this->locale($language);
    }

    public function build(): static
    {
        return $this->subject(__('email-change.subject'))
            ->view('mail.account-message', ['copy' => 'email-change', 'actionUrl' => $this->confirmationUrl])
            ->text('mail.text.email-change');
    }
}
