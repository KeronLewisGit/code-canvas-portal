<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Print-ready quotation, invoice and contract pages. Access goes through the model policies. */
class DocumentController extends Controller
{
    public function quotation(Quotation $quotation): View
    {
        $this->authorizeView($quotation);

        return view('documents.quotation', ['doc' => $quotation->load('client', 'items')]);
    }

    public function invoice(Invoice $invoice): View
    {
        $this->authorizeView($invoice);

        return view('documents.invoice', ['doc' => $invoice->load('client', 'items', 'contract')]);
    }

    public function contract(Contract $contract): View
    {
        $this->authorizeView($contract);
        Audit::log($contract, 'document_viewed');

        return view('documents.contract', ['contract' => $contract->load('client', 'signer')]);
    }

    /**
     * Statement of account: every issued invoice (charges) and verified payment (credits) in date order, with a
     * running balance, one section per currency. Admins can print any client's; a client only their own.
     */
    public function statement(Request $request, Client $client): View
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || ($user->isClient() && $user->client_id === $client->id), 404);

        $charges = $client->invoices()->whereNotIn('status', ['draft', 'cancelled'])->get()
            ->map(fn (Invoice $i) => ['date' => $i->issue_date, 'currency' => $i->currency, 'ref' => $i->reference, 'text' => $i->title, 'charge' => (float) $i->total, 'credit' => 0.0]);
        $credits = $client->payments()->where('status', 'verified')->with('invoice', 'contract')->get()
            ->map(fn ($p) => ['date' => $p->paid_on, 'currency' => $p->currency, 'ref' => $p->invoice?->reference ?? $p->contract?->reference ?? '',
                'text' => match ($p->method) {
                    'credit_note' => 'Credit note · '.$p->note, 'refund' => 'Refund to you', default => 'Payment received · '.$p->methodName()
                },
                // A refund is money going back to the client, so it raises the balance like a charge.
                'charge' => (float) $p->amount < 0 ? abs((float) $p->amount) : 0.0, 'credit' => max(0, (float) $p->amount)]);

        $sections = $charges->concat($credits)->sortBy(fn ($r) => [$r['date']->timestamp, $r['charge'] > 0 ? 0 : 1])->groupBy('currency')
            ->map(function ($rows) {
                $running = 0;

                return $rows->values()->map(function ($r) use (&$running) {
                    $running = round($running + $r['charge'] - $r['credit'], 2);

                    return $r + ['balance' => $running];
                });
            });

        return view('documents.statement', ['client' => $client, 'sections' => $sections]);
    }

    /** Printable receipt for a confirmed payment (shown as VOID if the payment was later reversed). */
    public function receipt(Payment $payment): View
    {
        $this->authorizeView($payment);
        abort_if($payment->receipt_number === null, 404);

        return view('documents.receipt', ['payment' => $payment->load('client', 'invoice', 'contract')]);
    }
}
