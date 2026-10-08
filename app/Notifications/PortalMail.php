<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One flexible email used for every portal event: subject, a few lines, one button. */
class PortalMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public array $lines, public ?string $actionLabel = null, public ?string $actionUrl = null, public ?string $greeting = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject.' · '.config('company.short'));
        $mail->greeting($this->greeting ?? ('Hello'.(isset($notifiable->name) ? ' '.$notifiable->name : '').','));
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->actionLabel && $this->actionUrl) {
            $mail->action($this->actionLabel, $this->actionUrl);
        }

        return $mail->salutation(config('company.name').' · '.config('company.phone').' · '.config('company.email'));
    }
}
