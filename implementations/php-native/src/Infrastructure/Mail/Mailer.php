<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Mail;

interface Mailer
{
    public function send(string $recipient, string $subject, string $textBody): void;
}
