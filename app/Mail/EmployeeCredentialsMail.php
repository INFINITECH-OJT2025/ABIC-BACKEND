<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;
    public $password;
    public $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct($employee, $password)
    {
        $this->employee = $employee;
        $this->password = $password;
        $this->loginUrl = config('app.frontend_url', 'http://localhost:3000') . '/login';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Employee Account Credentials - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-credentials',
            with: [
                'employeeName' => $this->employee->name,
                'employeeEmail' => $this->employee->email,
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
