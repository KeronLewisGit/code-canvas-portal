<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: contracts waiting for the client's signature get a reminder from config('company.signatures.first_after_days')
 * after they were sent, then every every_days. At overdue_after_days the reminders stop and admins are told once,
 * listing every contract that has waited that long, so they can follow up, re-send or withdraw and archive it.
 */
class SendSignatureReminders implements ShouldQueue
{
    use Queueable;

    public function handle(Notifier $notifier): void
    {
        $cfg = config('company.signatures');
        $waiting = Contract::with('client.users')->notArchived()->whereIn('status', ['approved', 'active'])
            ->whereNull('signed_at')->whereNotNull('sent_for_signature_at')->get()->filter->awaitingSignature();

        $overdue = collect();
        foreach ($waiting as $contract) {
            try {
                $days = (int) $contract->sent_for_signature_at->diffInDays(now());
                if ($contract->signatureOverdue()) {
                    // Alert once per signature request (a re-sent contract starts afresh).
                    $alerted = $contract->activity()->where('action', 'signature_overdue')->where('created_at', '>=', $contract->sent_for_signature_at)->exists();
                    if (! $alerted) {
                        $overdue->push($contract);
                    }

                    continue;
                }
                $lastReminder = $contract->reminded_at && $contract->reminded_at->gte($contract->sent_for_signature_at) ? $contract->reminded_at : null;
                // every_days - 1: the daily run drifts by seconds and would otherwise skip a cycle.
                $due = $days >= $cfg['first_after_days'] && (! $lastReminder || $lastReminder->lte(now()->subDays($cfg['every_days'] - 1)));
                if ($due) {
                    $notifier->remind($contract);
                }
            } catch (\Throwable $e) {
                report($e); // one contract's problem must not stop the others
            }
        }

        if ($overdue->isNotEmpty() && $notifier->signaturesOverdue($overdue)) {
            $overdue->each(fn (Contract $c) => Audit::log($c, 'signature_overdue', ['days' => (int) $c->sent_for_signature_at->diffInDays(now())]));
        }
    }
}
