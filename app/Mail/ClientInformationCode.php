<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The verification email. Sent synchronously from memory (never queued) so the code is never written to the jobs table.
 */
class ClientInformationCode extends Mailable
{
    public function __construct(public string $clientName, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Sellbees verification code');
    }

    public function content(): Content
    {
        $name = e($this->clientName);

        return new Content(htmlString: "<p>Hello {$name},</p><p>Your Sellbees verification code is:</p><p style=\"font-size:24px;letter-spacing:4px\"><strong>{$this->code}</strong></p>"
            ."<p>It expires in {$this->minutes} minutes and can be used once. If you did not ask for it, ignore this email; nobody can see your information without the code.</p>");
    }
}
