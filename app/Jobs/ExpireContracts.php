<?php

namespace App\Jobs;

use App\Services\ContractLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Marks active contracts past their end date as expired (renewing auto-renew ones first). Runs daily from the scheduler. */
class ExpireContracts implements ShouldQueue
{
    use Queueable;

    public function handle(ContractLifecycle $lifecycle): void
    {
        $lifecycle->expireDue();
    }
}
