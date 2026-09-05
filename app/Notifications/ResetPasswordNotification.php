<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = (string) config('app.frontend_url', 'http://localhost:5173');
        if (! str_starts_with($frontendUrl, 'http://') && ! str_starts_with($frontendUrl, 'https://')) {
            $frontendUrl = 'http://'.$frontendUrl;
        }
        $resetUrl = $frontendUrl.'/reset-password?token='.$this->token.'&email='.urlencode($this->email);

        $logoDataUri = null;
        $logoPath = public_path('images/custospark-academy-logo-email.png');
        if (file_exists($logoPath)) {
            $logoDataUri = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
        }

        return (new MailMessage)
            ->subject('Reset Your Custospark Academy Password')
            ->view('emails.standard', [
                'title' => 'Reset Your Custospark Academy Password',
                'brandName' => 'Custospark Academy',
                'tagline' => 'Learn. Build. Launch.',
                'logoUrl' => $logoDataUri,
                'mailBody' => '
                    <p>Hello <strong>'.e($notifiable->name).'</strong>,</p>
                    <p>You are receiving this email because we received a password reset request for your Custospark Academy account.</p>
                    <p style="font-size:14px; color:#94a6ba;">This password reset link will expire in 60 minutes.</p>
                    <p style="font-size:14px; color:#94a6ba;">If you did not request a password reset, no further action is required.</p>
                ',
                'ctaUrl' => $resetUrl,
                'ctaLabel' => 'Reset My Password',
                'tip' => 'Never share this email with anyone. Custospark Academy will never ask for your password.',
                'isHtml' => true,
            ]);
    }
}