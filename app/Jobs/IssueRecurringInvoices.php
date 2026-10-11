<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Services\ContractInvoicing;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily: each live recurring contract gets an invoice for every billing period that has started, and the client is emailed. */
class IssueRecurringInvoices implements ShouldQueue
{
    use Queueable;

    public function handle(ContractInvoicing $invoicing, Notifier $notifier): void
    {
        $ids = Contract::where('status', 'active')->where('billing_cycle', '!=', 'one_time')->pluck('id');
        foreach ($ids as $id) {
            try {
                foreach ($invoicing->issueDuePeriodInvoices(Contract::findOrFail($id)) as $invoice) {
                    $notifier->invoiceSent($invoice->load('client.users'));
                }
            } catch (\Throwable $e) {
                report($e); // one contract's problem must not stop the others being billed
            }
        }
    }
}
