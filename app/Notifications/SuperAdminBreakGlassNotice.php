<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperAdminBreakGlassNotice extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $action,
        private readonly string $occurredAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Talyvane Super Admin break-glass configuration updated')
            ->greeting('Super Admin security notice')
            ->line('The two-owner break-glass recovery configuration was '.$this->action.' at '.$this->occurredAt.'.')
            ->line('No passwords, authenticator secrets or verification codes are included in this message.')
            ->line('If you did not authorize this change, contact the deployment operator immediately and preserve this notice.')
            ->action('Open administrator sign-in', url('/admin/login'));
    }
}
