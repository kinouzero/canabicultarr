<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class MonitoringAlertMail extends Mailable
{
    public function __construct(public readonly string $deliveryId, public readonly string $title, public readonly string $messageText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.monitoring-alert', text: 'mail.text.monitoring-alert', with: ['messageText' => $this->messageText]);
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->deliveryId.'@canabicultarr.invalid');
    }
}
