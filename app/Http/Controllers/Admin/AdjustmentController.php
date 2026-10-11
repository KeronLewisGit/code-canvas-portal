<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Services\Adjustments;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Credit notes, refunds and late fees (see App\Services\Adjustments). */
class AdjustmentController extends Controller
{
    public function __construct(private Adjustments $adjustments) {}

    public function creditNote(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Give a reason; the client sees it.']);
        $credit = $this->adjustments->creditNote($invoice, (float) $data['amount'], $data['reason'], $request->user());
        app(Notifier::class)->creditNoteIssued($credit->load('client.users', 'invoice'));

        return back()->with('saved', 'Credit note of '.money($credit->amount, $credit->currency).' applied. '.$invoice->reference.' now has '.money($invoice->fresh()->balance(), $invoice->currency).' to pay.');
    }

    public function lateFee(Request $request, Invoice $invoice): RedirectResponse
    {
        $fee = $this->adjustments->lateFee($invoice, $request->user());
        app(Notifier::class)->invoiceSent($fee->load('client.users'));

        return back()->with('saved', 'Late fee invoice '.$fee->reference.' for '.money($fee->total, $fee->currency).' issued and emailed to the client.');
    }

    public function refundContract(Request $request, Contract $contract): RedirectResponse
    {
        return $this->refund($request, $contract);
    }

    public function refundInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        return $this->refund($request, $invoice);
    }

    private function refund(Request $request, Contract|Invoice $target): RedirectResponse
    {
        $data = $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:0.01'],
            'refund_method' => ['required', 'in:bank transfer,cheque,cash'],
            'refund_note' => ['nullable', 'string', 'max:500'],
        ]);
        $refund = $this->adjustments->refund($target, (float) $data['refund_amount'], $data['refund_method'], $data['refund_note'] ?? null, $request->user());
        app(Notifier::class)->refundIssued($refund->load('client.users'));

        return back()->with('saved', 'Refund of '.money(abs((float) $refund->amount), $refund->currency).' recorded and the client was emailed.');
    }
}
