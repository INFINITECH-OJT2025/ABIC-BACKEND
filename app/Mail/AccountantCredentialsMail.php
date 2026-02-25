<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountantCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $accountant;
    public $password;

    /**
     * Create a new message instance.
     */
    public function __construct(User $accountant, string $password)
    {
        $this->accountant = $accountant;
        $this->password = $password;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ABIC Accounting System Account Credentials',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.accountant-credentials',
            with: [
                'accountantName' => $this->accountant->name,
                'accountantEmail' => $this->accountant->email,
                'password' => $this->password,
                'loginUrl' => config('app.url') . '/login',
                'companyName' => config('app.name', 'ABIC Accounting System'),
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
