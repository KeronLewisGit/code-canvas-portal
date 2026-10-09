<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = Payment::with('client', 'contract', 'invoice')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")->latest('paid_on')->latest('id')->paginate(25)->withQueryString();

        return view('admin.payments.index', ['payments' => $payments, 'clients' => Client::orderBy('company')->get(['id', 'company']), 'pendingCount' => Payment::pending()->count()]);
    }

    /** Record a payment you received directly (counts as verified straight away). */
    public function create(Request $request): View
    {
        $contract = $request->filled('contract') ? Contract::with('client')->find($request->contract) : null;
        $invoice = $request->filled('invoice') ? Invoice::with('client')->find($request->invoice) : null;
        $payment = new Payment(['paid_on' => now(), 'method' => 'bank_transfer', 'currency' => $contract?->currency ?? $invoice?->currency ?? config('company.currency.code'), 'client_id' => $contract?->client_id ?? $invoice?->client_id, 'contract_id' => $contract?->id ?? $invoice?->contract_id, 'invoice_id' => $invoice?->id, 'amount' => $invoice?->balance() ?? $contract?->balance()]);

        return view('admin.payments.form', ['payment' => $payment, 'clients' => Client::orderBy('company')->get(), 'contracts' => Contract::with('client')->orderBy('reference')->get(), 'invoices' => Invoice::with('client')->whereNotIn('status', ['cancelled'])->orderBy('reference')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'currency' => ['required', 'in:'.implode(',', array_keys(config('company.currencies')))],
            'method' => ['required', 'in:'.implode(',', array_keys(config('company.payment_methods')))],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $data = $this->guardOwnership($data);
        unset($data['proof']);
        $proofPath = $request->hasFile('proof') ? $request->file('proof')->store('payments', 'local') : null;
        abort_if($proofPath === false, 500, 'The proof file could not be stored. Check the storage folder permissions.');

        $payment = Payment::create($data + ['status' => 'verified', 'submitted_by' => $request->user()->id, 'verified_by' => $request->user()->id, 'verified_at' => now(), 'proof_path' => $proofPath]);
        $payment->invoice?->refreshPaymentStatus();
        Audit::log($payment, 'recorded', ['amount' => (float) $payment->amount, 'method' => $payment->method]);

        return redirect()->route('admin.payments.show', $payment)->with('saved', 'Payment recorded and verified.');
    }

    public function show(Payment $payment): View
    {
        return view('admin.payments.show', ['payment' => $payment->load('client', 'contract', 'invoice', 'submitter', 'verifier')]);
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        if ($payment->status === 'verified') {
            return back()->with('saved', 'This payment was already verified.');
        }
        $payment->markVerified($request->user());
        Audit::log($payment, 'verified', ['amount' => (float) $payment->amount]);
        app(Notifier::class)->paymentDecided($payment->fresh(['client.users', 'invoice', 'contract']));

        return back()->with('saved', 'Payment verified. '.($payment->contract ? $payment->contract->reference.' is now '.$payment->contract->paymentState().'.' : ''));
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $reason = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']])['rejection_reason'];
        if ($payment->status === 'rejected') {
            return back()->with('saved', 'This payment was already rejected.');
        }
        $payment->markRejected($request->user(), $reason);
        Audit::log($payment, 'rejected', ['reason' => $reason]);
        app(Notifier::class)->paymentDecided($payment->fresh(['client.users', 'invoice', 'contract']));

        return back()->with('saved', 'Payment rejected. The client can see why and resubmit.');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        abort_if($payment->status === 'verified', 403, 'Verified payments are part of the financial record. Reverse the verification first if it was recorded in error.');
        $invoice = $payment->invoice;
        Audit::log($payment, 'deleted', ['amount' => (float) $payment->amount, 'status' => $payment->status, 'client' => $payment->client->company]);
        $payment->delete();
        if ($payment->proof_path) {
            Storage::disk('local')->delete($payment->proof_path); // after the row is gone, so a failed delete never strands a payment without its proof
        }
        $invoice?->refreshPaymentStatus();

        return redirect()->route('admin.payments.index')->with('saved', 'Payment deleted.');
    }

    /**
     * The contract and invoice must belong to the chosen client, the invoice must sit on the chosen contract,
     * and the payment must be in the document's currency (otherwise USD 500 would settle a TTD 500 invoice).
     * Problems come back as field errors with the form intact, not a bare 422 page.
     */
    private function guardOwnership(array $data): array
    {
        $contract = ! empty($data['contract_id']) ? Contract::find($data['contract_id']) : null;
        $invoice = ! empty($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;
        $errors = [];
        if ($contract && $contract->client_id !== (int) $data['client_id']) {
            $errors['contract_id'] = 'That contract belongs to a different client.';
        }
        if ($invoice && $invoice->client_id !== (int) $data['client_id']) {
            $errors['invoice_id'] = 'That invoice belongs to a different client.';
        }
        if ($invoice && $contract && $invoice->contract_id && $invoice->contract_id !== $contract->id) {
            $errors['invoice_id'] = 'That invoice is on a different contract. Leave the contract blank to take it from the invoice.';
        }
        $target = $invoice ?? $contract;
        if ($target && $target->currency !== $data['currency']) {
            $errors['currency'] = 'This '.($invoice ? 'invoice' : 'contract').' is in '.$target->currency.'. Record the payment in the same currency.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if ($invoice && ! $contract) {
            $data['contract_id'] = $invoice->contract_id;
        }

        return $data;
    }
}
