<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset notification that links to the Vue SPA reset page
 * instead of a backend web route.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * The password reset token.
     */
    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $broker      = config('auth.defaults.passwords');
        $expire      = config("auth.passwords.{$broker}.expire");

        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset Password - GH PIK2')
            ->greeting('Halo ' . ($notifiable->name ?? 'Pengguna') . '!')
            ->line('Kami menerima permintaan untuk mereset password akun Anda.')
            ->action('Reset Password', $resetUrl)
            ->line("Tautan reset password ini akan kedaluwarsa dalam {$expire} menit.")
            ->line('Jika Anda tidak meminta reset password, abaikan email ini dan tidak ada perubahan yang akan terjadi.')
            ->salutation('Terima kasih, Tim GH PIK2');
    }
}
