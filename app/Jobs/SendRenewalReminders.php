<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/** Emails admins a digest of contracts ending soon that have no renewal yet and will not renew themselves. Runs weekly from the scheduler. */
class SendRenewalReminders implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $days = (int) config('company.renewal_alert_days', 60);
        $ending = Contract::with('client')->live()->whereNotNull('end_date')->where('auto_renew', false)->whereDate('end_date', '<=', now()->addDays($days)->toDateString())->doesntHave('renewals')->orderBy('end_date')->get();
        if ($ending->isEmpty()) {
            return;
        }
        $count = $ending->count();
        // Failures are reported (logged) rather than thrown, so the scheduler keeps running the other jobs.
        rescue(fn () => Notification::send(User::where('role', 'admin')->get(), new PortalMail(
            $count.' contract'.($count === 1 ? '' : 's').' ending soon',
            ['These contracts end within '.$days.' days and have no renewal yet:', ...$ending->map(fn (Contract $c) => $c->reference.' · '.$c->client->company.' · '.$c->title.' · ends '.$c->end_date->format('j M Y'))->all()],
            'Open the revenue report',
            route('admin.reports.revenue'),
        )));
    }
}
