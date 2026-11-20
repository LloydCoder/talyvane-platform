<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SafeguardingCaseAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $caseReference,
        public readonly string $caseType,
        public readonly string $severity,
        public readonly string $caseUrl,
        public readonly bool $requiresAccount = true,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Restricted safeguarding case notice: '.$this->caseReference)
            ->greeting('Safeguarding notice')
            ->line('A safeguarding case requires attention.')
            ->line('Case reference: '.$this->caseReference)
            ->line('Category: '.str_replace('_', ' ', $this->caseType))
            ->line('Severity: '.strtoupper($this->severity))
            ->line('No case narrative, applicant contact information or identity-document details are included in this email.');

        if ($this->requiresAccount) {
            return $message
                ->action('Open restricted case workspace', $this->caseUrl)
                ->line('This email does not grant access. Sign in with your individual account and complete MFA before reviewing the case.');
        }

        return $message->line('This escalation notice does not provide case-content access. Follow your organization’s independently maintained safeguarding response procedure and do not reply with sensitive case information.');
    }
}
