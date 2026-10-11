<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\SortsListings;
use App\Http\Controllers\Admin\Concerns\ValidatesLineItems;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Notifier;
use App\Services\QuotationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuotationController extends Controller
{
    use SortsListings, ValidatesLineItems;

    public function index(Request $request): View
    {
        $quotations = Quotation::with('client')
            // "Expired" is usually not stored; it is a sent quotation past its valid-until date.
            ->when($request->status === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('status', 'expired')->orWhere(fn ($s) => $s->where('status', 'sent')->whereDate('valid_until', '<', now()->toDateString()))))
            ->when($request->filled('status') && $request->status !== 'expired', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->q.'%')->orWhere('reference', 'like', '%'.$request->q.'%')))
            ->tap(fn ($q) => $this->sorted($q, $request, ['issued' => 'issue_date', 'valid' => 'valid_until', 'total' => 'total'], fn ($q) => $q->latest('issue_date')->latest('id')))->paginate(25)->withQueryString();

        return view('admin.quotations.index', ['quotations' => $quotations, 'clients' => Client::orderBy('company')->get(['id', 'company'])]);
    }

    public function create(Request $request): View
    {
        $quotation = new Quotation(['issue_date' => now(), 'valid_until' => now()->addDays(30), 'currency' => config('company.currency.code'), 'tax_rate' => config('company.tax_rate'), 'status' => 'draft', 'client_id' => $request->integer('client') ?: null, 'contract_type' => array_key_first(config('company.contract_types')), 'billing_cycle' => 'one_time', 'auto_send_contract' => true]);

        return view('admin.quotations.form', $this->formData($quotation, []));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $items = $this->itemsFrom($request);
        $quotation = DB::transaction(function () use ($data, $items) {
            $quotation = Quotation::create($this->documentData($data));
            $quotation->syncItems($items);

            return $quotation;
        });

        if ($request->boolean('send') && app(QuotationWorkflow::class)->send($quotation)) {
            return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation '.$quotation->reference.' created and emailed to the client.');
        }

        return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation '.$quotation->reference.' saved as a draft. The client cannot see it until you send it.');
    }

    public function show(Quotation $quotation): View
    {
        return view('admin.quotations.show', ['quotation' => $quotation->load('client', 'items', 'contract', 'invoices')]);
    }

    public function edit(Quotation $quotation): View
    {
        abort_if($quotation->status === 'accepted', 403, 'The client accepted this quotation, so it is locked as the agreed record. Create a new quotation for any changes.');

        return view('admin.quotations.form', $this->formData($quotation, $quotation->items));
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->status === 'accepted', 403, 'The client accepted this quotation, so it is locked as the agreed record. Create a new quotation for any changes.');
        $data = $this->validated($request);
        $items = $this->itemsFrom($request);
        DB::transaction(function () use ($quotation, $data, $items) {
            $quotation->update($this->documentData($data));
            $quotation->syncItems($items);
        });

        if ($request->boolean('send') && $quotation->status === 'draft' && app(QuotationWorkflow::class)->send($quotation)) {
            return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation updated and emailed to the client.');
        }

        return redirect()->route('admin.quotations.show', $quotation)->with('saved', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->invoices()->exists() || $quotation->contract_id, 403, 'This quotation has an invoice or contract linked to it. Mark it declined or expired instead so the history stays intact.');
        $quotation->delete();

        return redirect()->route('admin.quotations.index')->with('saved', 'Quotation deleted.');
    }

    public function status(Request $request, Quotation $quotation, QuotationWorkflow $workflow): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys(config('company.quotation_statuses')))]])['status'];
        if ($quotation->status === 'accepted' && $quotation->contract_id && $status !== 'accepted') {
            return back()->withErrors(['status' => 'A contract was created from this acceptance. Cancel the contract instead of changing the quotation.']);
        }

        if ($status === 'sent') {
            return back()->with('saved', $workflow->send($quotation) ? 'Quotation sent. The client has been emailed a link to review it.' : 'Quotation marked sent.');
        }
        if (in_array($status, ['accepted', 'declined'])) {
            // Same path as the client answering in the portal, so the follow-up steps always run.
            $contract = $workflow->answer($quotation, $status === 'accepted', $request->user());

            return back()->with('saved', 'Quotation marked '.$status.'.'.match (true) {
                $contract?->awaitingSignature() => ' Contract '.$contract->reference.' was generated and sent to the client to sign.',
                $contract !== null => ' Contract '.$contract->reference.' was drafted from it. Review it and send it for signature.',
                default => ' The client has been emailed a confirmation.',
            });
        }
        $quotation->forceFill(['status' => $status, 'accepted_at' => null, 'declined_at' => null])->save();

        return back()->with('saved', 'Quotation marked '.strtolower($quotation->statusName()).'.');
    }

    public function remind(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($quotation->canBeAnswered(), 403, 'Only sent quotations that are still open can be reminded.');
        if (! app(Notifier::class)->remind($quotation->load('client.users'), $request->user())) {
            return back();
        }

        return back()->with('saved', 'Reminder emailed to '.$quotation->client->company.'.');
    }

    /** Copy the quotation into a new draft invoice. */
    public function toInvoice(Quotation $quotation): RedirectResponse
    {
        // Accepted quotations with a contract are billed from the contract (deposit, balance), not invoiced twice from here.
        abort_unless($quotation->status === 'accepted' && ! $quotation->contract_id, 403, 'Only accepted quotations without a contract can be invoiced directly. Invoice the contract instead.');
        $invoice = DB::transaction(function () use ($quotation) {
            $invoice = Invoice::create(['client_id' => $quotation->client_id, 'contract_id' => $quotation->contract_id, 'quotation_id' => $quotation->id, 'title' => $quotation->title, 'issue_date' => now(), 'due_date' => now()->addDays(14), 'currency' => $quotation->currency, 'tax_rate' => $quotation->tax_rate, 'status' => 'draft', 'notes' => $quotation->notes]);
            $invoice->syncItems($quotation->items->map(fn ($i) => $i->only('description', 'quantity', 'unit_price'))->all());

            return $invoice;
        });

        return redirect()->route('admin.invoices.edit', $invoice)->with('saved', 'Invoice '.$invoice->reference.' drafted from '.$quotation->reference.'.');
    }

    /** Draft the contract from the quotation by hand (acceptance does this automatically). One contract per quotation. */
    public function toContract(Quotation $quotation, QuotationWorkflow $workflow): RedirectResponse
    {
        if ($quotation->contract_id) {
            return redirect()->route('admin.contracts.show', $quotation->contract_id)->with('saved', 'This quotation already has a contract.');
        }
        $contract = $workflow->createContract($quotation);

        return redirect()->route('admin.contracts.edit', $contract)->with('saved', 'Contract '.$contract->reference.' drafted from '.$quotation->reference.'. Check the details, then save and send it for signature.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate($this->documentRules($request, [
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'contract_type' => ['nullable', 'in:'.implode(',', array_keys(config('company.contract_types')))],
            'billing_cycle' => ['nullable', 'in:'.implode(',', array_keys(config('company.billing_cycles')))],
            'contract_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]), $this->documentMessages());
        $data['auto_send_contract'] = $request->boolean('auto_send_contract');

        return $data;
    }

    private function formData(Quotation $quotation, iterable $items): array
    {
        return ['quotation' => $quotation, 'clients' => Client::orderBy('company')->get(), 'contracts' => Contract::with('client')->orderBy('reference')->get(), 'items' => $items];
    }
}
