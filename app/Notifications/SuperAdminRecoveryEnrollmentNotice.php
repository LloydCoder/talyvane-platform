<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperAdminRecoveryEnrollmentNotice extends Notification
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
            ->subject('Talyvane Super Admin recovery configuration updated')
            ->greeting('Super Admin security notice')
            ->line('A privileged Super Admin recovery configuration change was completed at '.$this->occurredAt.'.')
            ->line('No passwords, authenticator secrets, recovery keys or verification codes are included in this email.')
            ->line('All previous administrator sessions were invalidated.')
            ->line('If you did not authorize this enrollment, contact your hosting operator immediately and preserve this notice.')
            ->action('Open administrator sign-in', url('/admin/login'));
    }
}
