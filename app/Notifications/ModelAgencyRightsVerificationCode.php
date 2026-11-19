<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ModelAgencyRightsVerificationCode extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $requestType,
        public readonly string $expiresAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Talyvane applicant privacy request verification')
            ->greeting('Verify your applicant privacy request')
            ->line('A request was made to access or manage a Talyvane model-agency application.')
            ->line('Request type: '.$this->requestType.'.')
            ->line('Your one-time verification code is: '.$this->code)
            ->line('This code expires at '.$this->expiresAt.' and can be used once.')
            ->line('Talyvane staff will never ask you to share this code.')
            ->line('If you did not make this request, you can ignore this message.');
    }
}
