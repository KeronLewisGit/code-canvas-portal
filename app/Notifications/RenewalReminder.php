<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class RenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Collection $contracts) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->contracts->count().' contract'.($this->contracts->count() === 1 ? '' : 's').' ending soon')->line('These contracts end within '.config('company.renewal_alert_days').' days and have no renewal yet:');
        foreach ($this->contracts as $c) {
            $mail->line($c->reference.' · '.$c->client->company.' · '.$c->title.' · ends '.$c->end_date->format('j M Y'));
        }

        return $mail->action('Open the revenue report', route('admin.reports.revenue'));
    }
}
