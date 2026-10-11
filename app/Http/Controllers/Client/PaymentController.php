<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Notifier;
use App\Support\LedgerCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentController extends Controller
{
    private const MAX_PENDING = 5;

    public function index(Request $request): View|RedirectResponse
    {
        $client = $request->user()->client;
        $payments = $client->payments()->with('contract', 'invoice')->get();
        if ($payments->isEmpty() && ! $client->hasPayables()) {
            return $this->nothingToPay();
        }

        return view('portal.payments.index', ['payments' => $payments, 'canPay' => $client->hasPayables()]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $client = $request->user()->client;
        $contracts = $client->payableContracts();
        $invoices = $client->payableInvoices();
        if ($contracts->isEmpty() && $invoices->isEmpty()) {
            return $this->nothingToPay();
        }
        $invoice = $request->filled('invoice') ? $invoices->firstWhere('id', $request->integer('invoice')) : null;
        $contract = $request->filled('contract') ? $contracts->firstWhere('id', $request->integer('contract')) : $invoice?->contract;

        return view('portal.payments.form', [
            'contracts' => $contracts,
            'invoices' => $invoices,
            'payment' => new Payment(['paid_on' => now(), 'method' => 'bank_transfer', 'contract_id' => $contract?->id, 'invoice_id' => $invoice?->id, 'amount' => $invoice?->balance() ?: $contract?->balance(), 'currency' => $invoice?->currency ?? $contract?->currency ?? config('company.currency.code')]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $request->user()->client;
        $contracts = $client->payableContracts();
        $invoices = $client->payableInvoices();
        if ($contracts->isEmpty() && $invoices->isEmpty()) {
            return $this->nothingToPay();
        }
        // A handful of payments waiting for verification is plenty; more usually means duplicates.
        if ($client->payments()->pending()->count() >= self::MAX_PENDING) {
            throw ValidationException::withMessages(['amount' => 'You already have '.self::MAX_PENDING.' payments waiting for us to verify. We will check them shortly; contact us if something is wrong.']);
        }
        $data = $request->validate([
            'contract_id' => ['nullable', Rule::in($contracts->modelKeys())],
            'invoice_id' => ['nullable', Rule::in($invoices->modelKeys())],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', 'in:'.implode(',', array_keys(Payment::clientMethods()))],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
            'proof' => [Rule::requiredIf(fn () => Payment::methodNeedsProof($request->input('method', ''))), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ], [
            'proof.required' => 'Bank transfers need a screenshot or PDF of the transfer confirmation.',
            'contract_id.in' => 'That contract is not open for payment.',
            'invoice_id.in' => 'That invoice is not open for payment.',
        ]);

        $invoice = ! empty($data['invoice_id']) ? $invoices->find($data['invoice_id']) : null;
        $contract = ! empty($data['contract_id']) ? $contracts->find($data['contract_id']) : null;
        if (! $invoice && ! $contract) {
            throw ValidationException::withMessages(['contract_id' => 'Choose the contract or invoice this payment is for.']);
        }
        if ($invoice && $contract && $invoice->contract_id && $invoice->contract_id !== $contract->id) {
            throw ValidationException::withMessages(['contract_id' => 'That invoice is on a different contract. Pick one or the other.']);
        }
        // An invoice always decides the contract and currency so a payment can never credit the wrong contract.
        $target = $invoice ?? $contract;
        $data['contract_id'] = $invoice ? $invoice->contract_id : $contract->id;
        unset($data['proof']);

        $proofPath = $request->hasFile('proof') ? $request->file('proof')->store('payments', 'local') : null;
        if ($proofPath === false) {
            // The local disk does not throw; without this the payment would be saved with its proof silently missing.
            throw ValidationException::withMessages(['proof' => 'The file could not be saved. Please try again or contact us.']);
        }

        // The "not more than is owed" check and the save happen under a lock on the invoice or contract, so two
        // submissions at the same moment cannot both pass the check.
        try {
            $payment = DB::transaction(function () use ($target, $data, $client, $request, $proofPath) {
                $target::query()->lockForUpdate()->find($target->id);
                LedgerCache::flush();
                $owed = $target->balance() - (float) $target->payments()->where('status', 'pending')->sum('amount');
                if ((float) $data['amount'] > round($owed, 2) + 0.005) {
                    throw ValidationException::withMessages(['amount' => $owed > 0
                        ? 'That is more than the '.money($owed, $target->currency).' still owed on '.$target->reference.' (after payments already waiting to be verified).'
                        : 'Payments already waiting to be verified cover what is owed on '.$target->reference.'. We will update your balance once they are checked.']);
                }

                return $client->payments()->create($data + ['currency' => $target->currency, 'status' => 'pending', 'submitted_by' => $request->user()->id, 'proof_path' => $proofPath]);
            });
        } catch (ValidationException $e) {
            $proofPath && Storage::disk('local')->delete($proofPath); // nothing was saved, so do not keep the upload
            throw $e;
        }
        app(Notifier::class)->paymentSubmitted($payment->load('client.users', 'invoice'));

        return redirect()->route('portal.payments.show', $payment)->with('saved', 'Payment submitted. We will verify it and update your balance, usually within one working day.');
    }

    public function show(Request $request, Payment $payment): View
    {
        $this->authorizeView($payment);

        return view('portal.payments.show', ['payment' => $payment->load('contract', 'invoice')]);
    }

    private function nothingToPay(): RedirectResponse
    {
        return redirect()->route('portal.dashboard')->with('warning', 'There is nothing to pay yet. Payment options appear once we send you a contract or an invoice.');
    }
}
