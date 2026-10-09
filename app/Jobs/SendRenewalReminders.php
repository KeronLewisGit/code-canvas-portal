<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\RenewalReminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        foreach (User::where('role', 'admin')->get() as $admin) {
            $admin->notify(new RenewalReminder($ending));
        }
    }
}
