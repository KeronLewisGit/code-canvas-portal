<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ValidatesLineItems;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    use ValidatesLineItems;

    public function index(Request $request): View
    {
        $quotations = Quotation::with('client')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->latest('issue_date')->latest('id')->paginate(25)->withQueryString();

        return view('admin.quotations.index', ['quotations' => $quotations, 'clients' => Client::orderBy('company')->get(['id', 'company'])]);
    }

    public function create(Request $request): View
    {
        $quotation = new Quotation(['issue_date' => now(), 'valid_until' => now()->addDays(30), 'currency' => config('company.currency.code'), 'tax_rate' => config('company.tax_rate'), 'status' => 'draft', 'client_id' => $request->integer('client') ?: null]);

        return view('admin.quotations.form', ['quotation' => $quotation, 'clients' => Client::orderBy('company')->get(), 'contracts' => Contract::with('client')->orderBy('reference')->get(), 'items' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->documentRules(['valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'], 'intro' => ['nullable', 'string', 'max:5000']]));
        $items = $this->itemsFrom($request);
        $quotation = Quotation::create($this->documentData($data));
        $quotation->syncItems($items);

        return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation '.$quotation->reference.' created.');
    }

    public function show(Quotation $quotation): View
    {
        return view('admin.quotations.show', ['quotation' => $quotation->load('client', 'items', 'contract', 'invoices')]);
    }

    public function edit(Quotation $quotation): View
    {
        return view('admin.quotations.form', ['quotation' => $quotation, 'clients' => Client::orderBy('company')->get(), 'contracts' => Contract::with('client')->orderBy('reference')->get(), 'items' => $quotation->items]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate($this->documentRules(['valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'], 'intro' => ['nullable', 'string', 'max:5000']]));
        $quotation->update($this->documentData($data));
        $quotation->syncItems($this->itemsFrom($request));

        return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->invoices()->exists() || $quotation->contract_id, 403, 'This quotation has an invoice or contract linked to it. Mark it declined or expired instead so the history stays intact.');
        $quotation->delete();

        return redirect()->route('admin.quotations.index')->with('saved', 'Quotation deleted.');
    }

    public function status(Request $request, Quotation $quotation): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys(config('company.quotation_statuses')))]])['status'];
        $wasSent = $quotation->sent_at !== null;
        $quotation->forceFill(['status' => $status, 'sent_at' => $status === 'sent' ? ($quotation->sent_at ?? now()) : $quotation->sent_at, 'accepted_at' => $status === 'accepted' ? now() : null, 'declined_at' => $status === 'declined' ? now() : null])->save();

        if ($status === 'sent' && ! $wasSent) {
            app(Notifier::class)->quotationSent($quotation->fresh(['client.users']));

            return back()->with('saved', 'Quotation sent. The client has been emailed a link to review it.');
        }

        return back()->with('saved', 'Quotation marked '.strtolower($quotation->statusName()).'.');
    }

    public function remind(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($quotation->canBeAnswered(), 403, 'Only sent quotations that are still open can be reminded.');
        app(Notifier::class)->remind($quotation->load('client.users'), $request->user());

        return back()->with('saved', 'Reminder emailed to '.$quotation->client->company.'.');
    }

    /** Copy the quotation into a new draft invoice. */
    public function toInvoice(Quotation $quotation): RedirectResponse
    {
        $invoice = Invoice::create(['client_id' => $quotation->client_id, 'contract_id' => $quotation->contract_id, 'quotation_id' => $quotation->id, 'title' => $quotation->title, 'issue_date' => now(), 'due_date' => now()->addDays(14), 'currency' => $quotation->currency, 'tax_rate' => $quotation->tax_rate, 'status' => 'draft', 'notes' => $quotation->notes]);
        $invoice->syncItems($quotation->items->map(fn ($i) => $i->only('description', 'quantity', 'unit_price'))->all());

        return redirect()->route('admin.invoices.edit', $invoice)->with('saved', 'Invoice '.$invoice->reference.' drafted from '.$quotation->reference.'.');
    }

    /** Start a contract pre-filled from the quotation total. */
    public function toContract(Quotation $quotation): RedirectResponse
    {
        $contract = Contract::create(['client_id' => $quotation->client_id, 'title' => $quotation->title, 'type' => 'website', 'start_date' => now(), 'amount' => $quotation->total, 'billing_cycle' => 'one_time', 'currency' => $quotation->currency, 'status' => 'draft', 'scope' => $quotation->items->map(fn ($i) => '- '.$i->description)->implode("\n")]);
        $quotation->update(['contract_id' => $contract->id]);

        return redirect()->route('admin.contracts.edit', $contract)->with('saved', 'Contract '.$contract->reference.' drafted from '.$quotation->reference.'. Check the type and dates.');
    }
}
