<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperAdminRecoveryContactsRotatedNotice extends Notification
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
            ->subject('Talyvane Super Admin recovery contacts changed')
            ->greeting('Super Admin security notice')
            ->line('The recovery notification address and offline recovery key were rotated at '.$this->occurredAt.'.')
            ->line('All previous administrator sessions were invalidated.')
            ->line('The replacement offline recovery key is not included in this email.')
            ->line('If you did not authorize this change, contact your hosting operator immediately and preserve this notice.')
            ->action('Open administrator sign-in', url('/admin/login'));
    }
}
