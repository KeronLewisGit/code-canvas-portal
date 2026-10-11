<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;

/**
 * What a client still has to do, shown as counts on the portal menu. A count clears only when the action is done,
 * never just by looking: quotations to answer, contracts to sign, and invoices with money still owed (not already
 * covered by a payment waiting to be verified).
 */
class PendingActions
{
    /** @var array<int, array{quotations: int, contracts: int, payments: int}> per-client memo for one request */
    private array $memo = [];

    /** @return array{quotations: int, contracts: int, payments: int} */
    public function counts(?Client $client): array
    {
        if (! $client) {
            return ['quotations' => 0, 'contracts' => 0, 'payments' => 0];
        }

        return $this->memo[$client->id] ??= [
            'quotations' => $client->quotations()->where('status', 'sent')->get()->filter->canBeAnswered()->count(),
            'contracts' => $client->contracts()->visibleToClient()->get()->filter->awaitingSignature()->count(),
            'payments' => $client->payableInvoices()->filter(fn (Invoice $i) => $i->balance() - (float) $i->payments()->where('status', 'pending')->sum('amount') > 0.005)->count(),
        ];
    }
}
