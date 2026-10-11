<?php

use App\Jobs\ExpireContracts;
use App\Jobs\IssueRecurringInvoices;
use App\Jobs\SendPaymentReminders;
use App\Jobs\SendRenewalReminders;
use Illuminate\Support\Facades\Schedule;

// Hostinger: add a cron entry `* * * * * cd ~/public_html && php artisan schedule:run >> storage/logs/cron.log 2>&1`.
// With QUEUE_CONNECTION=sync these jobs run inside the scheduler process; no worker is needed.
// Times are in the app timezone (config/app.php, America/Port_of_Spain by default).
// withoutOverlapping: a slow run (e.g. a slow mail server) is never started a second time on top of itself.
Schedule::job(new ExpireContracts)->dailyAt('01:00')->withoutOverlapping();
Schedule::job(new IssueRecurringInvoices)->dailyAt('06:00')->withoutOverlapping(); // after expiry/renewal, before the working day
Schedule::job(new SendRenewalReminders)->weeklyOn(1, '08:00')->withoutOverlapping();
Schedule::job(new SendPaymentReminders)->weeklyOn(2, '09:00')->withoutOverlapping();
