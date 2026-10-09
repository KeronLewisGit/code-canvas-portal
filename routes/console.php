<?php

use App\Jobs\ExpireContracts;
use App\Jobs\SendPaymentReminders;
use App\Jobs\SendRenewalReminders;
use Illuminate\Support\Facades\Schedule;

// Hostinger: add a cron entry `* * * * * cd ~/public_html && php artisan schedule:run >> /dev/null 2>&1`.
// With QUEUE_CONNECTION=sync these jobs run inside the scheduler process; no worker is needed.
// Times are in the app timezone (config/app.php, America/Port_of_Spain by default).
Schedule::job(new ExpireContracts)->dailyAt('01:00');
Schedule::job(new SendRenewalReminders)->weeklyOn(1, '08:00');
Schedule::job(new SendPaymentReminders)->weeklyOn(2, '09:00');
