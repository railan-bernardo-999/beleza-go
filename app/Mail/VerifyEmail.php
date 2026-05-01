<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $verificationUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->verificationUrl = $this->generateVerificationUrl();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
             to: $this->user->email,
            subject: 'Verifique seu endereço de email - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email', // View que vamos criar
            // with: [
            //     'userName' => $this->user->name,
            //     'verificationUrl' => $this->verificationUrl,
            //     'appName' => config('app.name'),
            //     'supportEmail' => config('mail.support_email', 'suporte@exemplo.com')
            // ]
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

    /**
     * Generate verification URL
     */
    private function generateVerificationUrl(): string
    {
        // URL para API
       // return url('/api/auth/verify-email?token=' . $this->user->verification_token);

        // Se for SPA com frontend separado:
        return config('app.frontend_url') . '/verify-email?token=' . $this->user->verification_token;
    }
}
