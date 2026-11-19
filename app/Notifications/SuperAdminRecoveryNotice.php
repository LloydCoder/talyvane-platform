<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperAdminRecoveryNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly string $occurredAt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Talyvane Super Admin security recovery completed')
            ->greeting('Super Admin security notice')
            ->line('A deployment Super Admin MFA recovery was completed at '.$this->occurredAt.'.')
            ->line('The previous authenticator, MFA recovery codes and all prior administrator sessions were invalidated.')
            ->line('A new offline deployment recovery key was issued. It is not included in this email.')
            ->line('If you did not authorize this recovery, contact your hosting operator immediately and preserve this notice.')
            ->action('Open administrator sign-in', url('/admin/login'));
    }
}
