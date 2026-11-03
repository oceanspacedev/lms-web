<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Dikirim langsung (tidak di-queue) agar kegagalan SMTP terlihat di halaman login.
 */
class EmailLoginMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $otp,
        public string $loginUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode masuk '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-login',
        );
    }
}
