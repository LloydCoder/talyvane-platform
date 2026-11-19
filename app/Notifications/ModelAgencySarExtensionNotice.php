<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ModelAgencySarExtensionNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly string $newDueAt)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update to your Talyvane applicant access request')
            ->greeting('Applicant privacy request update')
            ->line('The agency requires additional time to respond to your subject access request.')
            ->line('The revised response due date is '.$this->newDueAt.'.')
            ->line('If you have questions, use the controller contact details in the published model-applicant privacy notice.')
            ->line('This message does not include your application data.');
    }
}
