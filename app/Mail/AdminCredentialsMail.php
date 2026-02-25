<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;
    public $password;
    public $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct($admin, $password)
    {
        $this->admin = $admin;
        $this->password = $password;
        $this->loginUrl = config('app.frontend_url', 'http://localhost:3000') . '/auth/login';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Admin Account Credentials - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-credentials',
            with: [
                'adminName' => $this->admin->name,
                'adminEmail' => $this->admin->email,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
                'appName' => config('app.name'),
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
