<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;
    public $activationToken;

    /**
     * Create a new message instance.
     */
    public function __construct($admin, $activationToken)
    {
        $this->admin = $admin;
        $this->activationToken = $activationToken;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activate Your Admin Account - ABIC Accounting System',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-activation',
            with: [
                'adminName' => $this->admin->name,
                'activationUrl' => config('app.frontend_url') . '/admin/activate?token=' . $this->activationToken,
                'supportEmail' => config('mail.support_address', 'support@abic.com'),
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
