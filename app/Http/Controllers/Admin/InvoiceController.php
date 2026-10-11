<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ValidatesLineItems;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Services\ContractInvoicing;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    use ValidatesLineItems;

    public function index(Request $request): View
    {
        $invoices = Invoice::with('client')
            // "Overdue" is never stored; it is a sent/partial invoice past its due date.
            ->when($request->status === 'overdue', fn ($q) => $q->whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', now()->toDateString()))
            ->when($request->filled('status') && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->q.'%')->orWhere('reference', 'like', '%'.$request->q.'%')))
            ->latest('issue_date')->latest('id')->paginate(25)->withQueryString();

        return view('admin.invoices.index', ['invoices' => $invoices, 'clients' => Client::orderBy('company')->get(['id', 'company'])]);
    }

    public function create(Request $request): View
    {
        $contract = $request->filled('contract') ? Contract::find($request->contract) : null;
        $kind = $contract && $request->query('kind') === 'balance' ? 'balance' : 'standard';
        $invoice = new Invoice([
            'issue_date' => now(),
            'due_date' => now()->addDays($contract ? (int) $contract->payment_terms_days : 14),
            'currency' => $contract?->currency ?? config('company.currency.code'),
            // A contract price already includes any tax from its quotation, so billing it adds none on top.
            'tax_rate' => $contract ? 0 : config('company.tax_rate'),
            'status' => 'draft',
            'kind' => $kind,
            'client_id' => $contract?->client_id ?? ($request->integer('client') ?: null),
            'contract_id' => $contract?->id,
            'title' => $contract ? ($kind === 'balance' ? 'Balance: '.$contract->title : $contract->title) : null,
        ]);
        // What is still left to bill: the remainder of a one-off price, or one period of a recurring one.
        $items = $contract ? [['description' => $invoice->title.' ('.$contract->reference.')', 'quantity' => 1, 'unit_price' => ContractInvoicing::balanceToInvoice($contract)]] : [];

        return view('admin.invoices.form', $this->formData($invoice, $items));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $items = $this->itemsFrom($request);
        $invoice = DB::transaction(function () use ($data, $items) {
            $invoice = Invoice::create($this->documentData($data) + ['sent_at' => $data['status'] === 'sent' ? now() : null]);
            $invoice->syncItems($items);

            return $invoice;
        });
        if ($data['status'] === 'sent') {
            app(Notifier::class)->invoiceSent($invoice->fresh(['client.users']));

            return redirect()->route('admin.invoices.show', $invoice)->with('saved', 'Invoice '.$invoice->reference.' created and emailed to the client.');
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('saved', 'Invoice '.$invoice->reference.' created as a draft.');
    }

    public function show(Invoice $invoice): View
    {
        return view('admin.invoices.show', ['invoice' => $invoice->load('client', 'items', 'contract', 'quotation', 'payments.submitter')]);
    }

    public function edit(Invoice $invoice): View
    {
        abort_if(in_array($invoice->status, ['paid', 'cancelled']), 403, 'Paid and cancelled invoices are part of the record and cannot be edited. Create a new invoice instead.');

        return view('admin.invoices.form', $this->formData($invoice, $invoice->items));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if(in_array($invoice->status, ['paid', 'cancelled']), 403, 'Paid and cancelled invoices are part of the record and cannot be edited. Create a new invoice instead.');
        $data = $this->validated($request);
        $items = $this->itemsFrom($request);
        if ($invoice->payments()->exists()) {
            // Payments are recorded in the invoice's currency and against its client and contract, so those are fixed now.
            $errors = array_filter([
                'client_id' => (int) $data['client_id'] !== $invoice->client_id || (int) ($data['contract_id'] ?? 0) !== (int) $invoice->contract_id ? 'The client and contract cannot change once payments are recorded against this invoice.' : null,
                'currency' => $data['currency'] !== $invoice->currency ? 'The currency cannot change once payments are recorded in '.$invoice->currency.'.' : null,
                'status' => $data['status'] === 'draft' && $invoice->status !== 'draft' ? 'This invoice has payments, so it cannot go back to draft.' : null,
            ]);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }
        $wasSent = $invoice->sent_at !== null;
        DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->update(collect($this->documentData($data))->except('status')->all());
            $invoice->syncItems($items);
            if (in_array($invoice->status, ['draft', 'sent'])) {
                $invoice->forceFill(['status' => $data['status'], 'sent_at' => $data['status'] === 'sent' ? ($invoice->sent_at ?? now()) : $invoice->sent_at])->save();
            }
            $invoice->refresh()->refreshPaymentStatus();
        });
        if (! $wasSent && $invoice->sent_at) {
            app(Notifier::class)->invoiceSent($invoice->fresh(['client.users']));

            return redirect()->route('admin.invoices.show', $invoice)->with('saved', 'Invoice updated and emailed to the client.');
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('saved', 'Invoice updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->payments()->exists() || in_array($invoice->status, ['paid', 'partial']), 403, 'This invoice has payments recorded against it. Cancel it instead so the payment history stays intact.');
        $invoice->delete();

        return redirect()->route('admin.invoices.index')->with('saved', 'Invoice deleted.');
    }

    public function status(Request $request, Invoice $invoice): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:draft,sent,paid,cancelled']])['status'];
        $hasPayments = $invoice->payments()->exists();
        if ($hasPayments && in_array($status, ['draft', 'cancelled'])) {
            return back()->withErrors(['status' => 'This invoice has payments recorded against it, so it cannot go back to draft or be cancelled. Reject or delete the payments first.']);
        }
        $wasSent = $invoice->sent_at !== null;
        // Marking paid by hand records the remaining balance as a verified payment, so the contract balance, reports
        // and the client's payment history all agree that it was paid.
        if ($status === 'paid' && ($owed = $invoice->balance()) > 0) {
            $payment = $invoice->payments()->create(['client_id' => $invoice->client_id, 'contract_id' => $invoice->contract_id, 'amount' => $owed, 'currency' => $invoice->currency, 'method' => 'other', 'paid_on' => now(), 'note' => 'Marked paid by '.$request->user()->name.'.', 'status' => 'verified', 'submitted_by' => $request->user()->id, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            Audit::log($payment, 'recorded', ['amount' => $owed, 'method' => 'other', 'via' => 'invoice marked paid']);
        }
        $invoice->forceFill(['status' => $status, 'sent_at' => in_array($status, ['sent', 'paid']) ? ($invoice->sent_at ?? now()) : $invoice->sent_at, 'paid_at' => $status === 'paid' ? ($invoice->paid_at ?? now()) : null])->save();
        if ($status === 'sent') {
            $invoice->refreshPaymentStatus();
        }

        if ($status === 'sent' && ! $wasSent) {
            app(Notifier::class)->invoiceSent($invoice->fresh(['client.users']));

            return back()->with('saved', 'Invoice sent. The client has been emailed a link to view and pay it.');
        }

        return back()->with('saved', 'Invoice marked '.strtolower($invoice->statusName()).'.');
    }

    public function remind(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless(in_array($invoice->status, ['sent', 'partial']) && $invoice->balance() > 0, 403, 'Only unpaid, sent invoices can be reminded.');
        if (! app(Notifier::class)->remind($invoice->load('client.users'), $request->user())) {
            return back(); // the Notifier has flashed why it could not be sent
        }

        return back()->with('saved', 'Payment reminder emailed to '.$invoice->client->company.'.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate($this->documentRules($request, ['due_date' => ['nullable', 'date', 'after_or_equal:issue_date'], 'status' => ['required', 'in:draft,sent'], 'kind' => ['sometimes', 'in:'.implode(',', array_keys(ContractInvoicing::KINDS))]]), $this->documentMessages());
        // An invoice on a contract bills that contract, so it must be in the contract's currency.
        $contract = ! empty($data['contract_id']) ? Contract::find($data['contract_id']) : null;
        if ($contract && $contract->currency !== $data['currency']) {
            throw ValidationException::withMessages(['currency' => 'Contract '.$contract->reference.' is in '.$contract->currency.'. Invoice it in the same currency.']);
        }

        return $data;
    }

    private function formData(Invoice $invoice, iterable $items): array
    {
        return ['invoice' => $invoice, 'clients' => Client::orderBy('company')->get(), 'contracts' => Contract::with('client')->orderBy('reference')->get(), 'items' => $items];
    }
}
