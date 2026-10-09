<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Weekly: remind clients about overdue invoices, no more than once a week per invoice. */
class SendPaymentReminders implements ShouldQueue
{
    use Queueable;

    public function handle(Notifier $notifier): void
    {
        $ids = Invoice::whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(6)))->pluck('id'); // 6, not 7: the weekly run drifts by seconds and would otherwise skip a week
        foreach ($ids as $id) {
            try {
                $invoice = Invoice::with('client.users')->find($id);
                if ($invoice && $invoice->balance() > 0) {
                    $notifier->remind($invoice);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
