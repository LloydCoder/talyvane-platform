<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperAdminEmailVerificationCode extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly string $purpose,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Talyvane Super Admin notification address verification')
            ->greeting('Verify your notification address')
            ->line('Use this one-time code to verify the '.$this->purpose.':')
            ->line($this->code)
            ->line('The code is valid only during the active provisioning or recovery-enrollment terminal session and expires after five minutes.')
            ->line('Do not share this code with anyone.');
    }
}
