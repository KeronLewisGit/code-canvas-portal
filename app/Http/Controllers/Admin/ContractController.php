<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\SortsListings;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Services\ContractInvoicing;
use App\Services\ContractLifecycle;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContractController extends Controller
{
    use SortsListings;

    public function __construct(private ContractLifecycle $lifecycle) {}

    public function index(Request $request): View
    {
        $contracts = Contract::with('client')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('cycle'), fn ($q) => $q->where('billing_cycle', $request->cycle))
            ->when($request->query('view') === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->notArchived())
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->q.'%')->orWhere('reference', 'like', '%'.$request->q.'%')))
            ->tap(fn ($q) => $this->sorted($q, $request, ['start' => 'start_date', 'amount' => 'amount'], fn ($q) => $q->orderByRaw("case status when 'active' then 0 when 'approved' then 1 when 'draft' then 2 else 3 end")->latest('start_date')))->paginate(25)->withQueryString();

        return view('admin.contracts.index', ['contracts' => $contracts, 'clients' => Client::orderBy('company')->get(['id', 'company'])]);
    }

    public function create(Request $request): View
    {
        $contract = new Contract(['status' => 'draft', 'currency' => config('company.currency.code'), 'start_date' => now(), 'client_id' => $request->integer('client') ?: null, 'type' => 'website', 'billing_cycle' => 'one_time', 'payment_terms_days' => 14, 'company_signatory' => config('company.signatory')]);

        return view('admin.contracts.form', ['contract' => $contract, 'clients' => Client::orderBy('company')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $contract = DB::transaction(function () use ($request, $data) {
            $contract = Contract::create($data);
            $this->storeFile($request, $contract);
            Audit::log($contract, 'created', ['status' => $contract->status]);

            return $contract;
        });

        if ($invoice = $this->issueFirstInvoice($contract)) {
            return redirect()->route('admin.contracts.show', $contract)->with('saved', 'Contract '.$contract->reference.' created. Invoice '.$invoice->reference.' was issued and emailed to the client.');
        }
        if ($request->boolean('send') && ContractLifecycle::canSendForSignature($contract)) {
            $this->lifecycle->sendForSignature($contract, $request->user());

            return redirect()->route('admin.contracts.show', $contract)->with('saved', 'Contract '.$contract->reference.' created and sent to the client to sign.');
        }

        return redirect()->route('admin.contracts.show', $contract)->with('saved', 'Contract '.$contract->reference.' saved as a draft. The client cannot see it until you send it for signature.');
    }

    public function show(Contract $contract): View
    {
        $contract->load(['client', 'invoices', 'quotations', 'payments.invoice', 'payments.submitter', 'versions.author', 'renewalOf', 'renewals', 'approver']);

        return view('admin.contracts.show', ['contract' => $contract, 'finance' => $contract->finance()->summary(), 'actions' => ContractLifecycle::available($contract), 'activity' => $contract->activity()->with('user')->take(8)->get()]);
    }

    public function edit(Contract $contract): View
    {
        return view('admin.contracts.form', ['contract' => $contract, 'clients' => Client::orderBy('company')->get()]);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $data = $this->validated($request);
        if ($data['currency'] !== $contract->currency && ($contract->payments()->exists() || $contract->invoices()->where('status', '!=', 'cancelled')->exists())) {
            throw ValidationException::withMessages(['currency' => 'This contract already has invoices or payments in '.$contract->currency.', so its currency cannot change. Cancel it and create a new one if the currency is wrong.']);
        }
        $reason = $request->input('amendment_reason');
        $wasVersion = $contract->version;
        DB::transaction(function () use ($request, $contract, $data, $reason) {
            $this->lifecycle->amend($contract, $data, $request->user(), $reason);
            $this->storeFile($request, $contract);
        });
        $message = $contract->version > $wasVersion ? 'Contract amended. Version '.$wasVersion.' is kept on record.' : 'Contract updated.';
        if ($invoice = $this->issueFirstInvoice($contract)) {
            $message .= ' Invoice '.$invoice->reference.' was issued and emailed to the client.';
        }
        if ($request->boolean('send') && ! $contract->sent_for_signature_at && ContractLifecycle::canSendForSignature($contract->refresh())) {
            $this->lifecycle->sendForSignature($contract, $request->user());
            $message .= ' It has been sent to the client to sign.';
        }

        return redirect()->route('admin.contracts.show', $contract)->with('saved', $message);
    }

    /** Send for signature, withdraw the request, or clear a signature so the client can re-sign. */
    public function signature(Request $request, Contract $contract): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:request,withdraw,clear']])['action'];

        // Preconditions come back as a form error on the contract page, like the lifecycle actions, not a bare 403.
        $problem = match ($action) {
            'request' => ContractLifecycle::canSendForSignature($contract) ? null : 'Only unsigned draft, approved or active contracts can be sent for signature.',
            'withdraw' => $contract->sent_for_signature_at && ! $contract->isSigned() ? null : 'There is no open signature request to withdraw.',
            default => $contract->isClosed() ? 'The signature on a '.strtolower($contract->statusName()).' contract is part of the record and cannot be cleared.' : ($contract->isSignedInPortal() ? null : 'This contract has no portal signature to clear.'),
        };
        if ($problem) {
            return back()->withErrors(['action' => $problem]);
        }

        match ($action) {
            'request' => $this->lifecycle->sendForSignature($contract, $request->user()),
            'withdraw' => $contract->forceFill(['sent_for_signature_at' => null])->save(),
            default => $this->clearSignature($contract),
        };

        return back()->with('saved', match ($action) {
            'request' => 'The client has been emailed and can now sign '.$contract->reference.' in their portal. It activates when they sign.',
            'withdraw' => 'Signature request withdrawn.',
            default => 'Signature cleared. Send it for signature again when ready.',
        });
    }

    /** Draft and sent invoices with no payments at all are cancelled; anything with money against it is kept. */
    private function voidUnpaidInvoices(Contract $contract): int
    {
        $invoices = $contract->invoices()->whereIn('status', ['draft', 'sent'])->whereDoesntHave('payments')->get();
        $invoices->each(fn (Invoice $i) => $i->forceFill(['status' => 'cancelled'])->save());
        $invoices->isNotEmpty() && Audit::log($contract, 'invoices_cancelled', ['invoices' => $invoices->pluck('reference')->implode(', ')]);

        return $invoices->count();
    }

    /** A signed, live contract gets its first invoice (deposit, full or first period) and the client is emailed it. */
    private function issueFirstInvoice(Contract $contract): ?Invoice
    {
        $invoice = app(ContractInvoicing::class)->issueFirstInvoice($contract->refresh());
        $invoice && app(Notifier::class)->invoiceSent($invoice->load('client.users'));

        return $invoice;
    }

    private function clearSignature(Contract $contract): void
    {
        $path = $contract->signature_path;
        DB::transaction(function () use ($contract) {
            $contract->forceFill(['signature_path' => null, 'signed_name' => null, 'signed_ip' => null, 'signed_by' => null, 'signed_at' => null, 'sent_for_signature_at' => null, 'standard_terms' => null])->save();
            Audit::log($contract, 'signature_cleared');
        });
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    /** approve | activate | complete | terminate | cancel | reopen */
    public function lifecycle(Request $request, Contract $contract): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:'.implode(',', array_keys(ContractLifecycle::TRANSITIONS))], 'reason' => ['nullable', 'string', 'max:1000'], 'terminated_at' => ['nullable', 'date']]);
        if (! ContractLifecycle::can($contract, $data['action'])) {
            return back()->withErrors(['action' => 'That step is not available while the contract is '.$contract->statusName().'.']);
        }
        if ($data['action'] === 'terminate' && empty($data['reason'])) {
            return back()->withErrors(['reason' => 'Give a reason for the termination; it goes on the audit log.']);
        }
        $this->lifecycle->transition($contract, $data['action'], $request->user(), array_filter(['reason' => $data['reason'] ?? null, 'terminated_at' => isset($data['terminated_at']) ? Carbon::parse($data['terminated_at']) : null]));
        $contract = $contract->fresh(['client.users']);
        app(Notifier::class)->contractStatus($contract, $data['action']);
        $invoice = $this->issueFirstInvoice($contract);
        $note = match ($data['action']) {
            // A cancelled contract was never in effect: its unpaid invoices are voided so they stop asking for payment.
            'cancel' => ($voided = $this->voidUnpaidInvoices($contract)) ? ' '.$voided.' unpaid invoice'.($voided === 1 ? ' was' : 's were').' cancelled with it.' : '',
            // A terminated contract may still owe for work done, so open invoices are left for the admin to decide.
            'complete' => ! $contract->isRecurring() && ($left = (float) $contract->finance()->remainingValue()) > 0 ? ' '.money($left, $contract->currency).' is not invoiced yet: use "Create balance invoice".' : '',
            'terminate' => ($open = $contract->invoices()->whereIn('status', ['sent', 'partial'])->pluck('reference'))->isNotEmpty() ? ' Still open: '.$open->implode(', ').'. Cancel any that should no longer be paid.' : '',
            default => '',
        };

        return back()->with('saved', 'Contract is now '.strtolower($contract->statusName()).'.'.($invoice ? ' Invoice '.$invoice->reference.' was issued and emailed to the client.' : '').$note);
    }

    /**
     * Archive a contract to take it out of the working lists. A closed one is archived as it is. One that was never
     * signed (e.g. waiting weeks for a signature) is withdrawn first: cancelled, its unpaid invoices voided, and the
     * client told if it had been sent to them. It then disappears from their portal; signed contracts stay on record.
     */
    public function archive(Request $request, Contract $contract): RedirectResponse
    {
        if (! $contract->canBeArchived()) {
            return back()->withErrors(['action' => $contract->isArchived() ? 'This contract is already archived.' : 'An active contract cannot be archived. Complete or terminate it first.']);
        }
        $withdrawn = false;
        $voided = 0;
        if (in_array($contract->status, ['draft', 'approved'])) {
            $wasSent = $contract->isVisibleToClient();
            $this->lifecycle->transition($contract, 'cancel', $request->user(), ['reason' => 'Withdrawn and archived']);
            $voided = $this->voidUnpaidInvoices($contract);
            $wasSent && app(Notifier::class)->contractStatus($contract->fresh(['client.users']), 'cancel');
            $withdrawn = true;
        }
        $contract->forceFill(['archived_at' => now()])->save();
        Audit::log($contract, 'archived', ['withdrawn' => $withdrawn]);

        return redirect()->route('admin.contracts.index')->with('saved', 'Contract '.$contract->reference.($withdrawn ? ' was withdrawn and archived.' : ' was archived.').($voided ? ' '.$voided.' unpaid invoice'.($voided === 1 ? ' was' : 's were').' cancelled.' : '').' Find it under "Archived".');
    }

    public function unarchive(Contract $contract): RedirectResponse
    {
        $contract->forceFill(['archived_at' => null])->save();
        Audit::log($contract, 'unarchived');

        return back()->with('saved', 'Contract '.$contract->reference.' is back in your lists.'.($contract->status === 'cancelled' ? ' It is still cancelled; reopen it to use it again.' : ''));
    }

    public function remind(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->awaitingSignature(), 403, 'Only contracts awaiting signature can be reminded.');
        if (! app(Notifier::class)->remind($contract->load('client.users'), $request->user())) {
            return back();
        }

        return back()->with('saved', 'Signature reminder emailed to '.$contract->client->company.'.');
    }

    public function renew(Request $request, Contract $contract): RedirectResponse
    {
        if ($existing = $contract->renewals()->first()) {
            return redirect()->route('admin.contracts.show', $existing)->with('saved', $contract->reference.' already has a renewal, '.$existing->reference.'.');
        }
        if (! ($contract->isLive() || $contract->effectiveStatus() === 'expired')) {
            return back()->withErrors(['action' => 'Only active or expired contracts can be renewed.']);
        }
        $renewal = DB::transaction(fn () => $this->lifecycle->renew($contract, $request->user()));

        return redirect()->route('admin.contracts.edit', $renewal)->with('saved', 'Renewal '.$renewal->reference.' drafted from '.$contract->reference.'. Check the dates and amount, then approve it.');
    }

    public function version(Contract $contract, int $version): View
    {
        $record = $contract->versions()->where('version', $version)->firstOrFail();

        return view('admin.contracts.version', ['contract' => $contract, 'record' => $record, 'current' => $contract->materialSnapshot()]);
    }

    public function activity(Contract $contract): View
    {
        return view('admin.contracts.activity', ['contract' => $contract, 'activity' => $contract->activity()->with('user')->paginate(50)]);
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        abort_if($contract->isSigned() || $contract->payments()->exists() || $contract->invoices()->exists(), 403, 'Signed, invoiced or paid contracts cannot be deleted. Terminate or cancel them instead so the record is kept.');
        $this->lifecycle->destroyFiles($contract);
        $contract->delete();

        return redirect()->route('admin.contracts.index')->with('saved', 'Contract deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(config('company.contract_types')))],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'billing_cycle' => ['required', 'in:'.implode(',', array_keys(config('company.billing_cycles')))],
            'currency' => ['required', 'in:'.implode(',', array_keys(config('company.currencies')))],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_renew' => ['nullable', 'boolean'],
            'client_signatory' => ['nullable', 'string', 'max:120'],
            'company_signatory' => ['nullable', 'string', 'max:120'],
            'confidential' => ['nullable', 'boolean'],
            'amendment_reason' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:10000'],
            'signed_at' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'remove_file' => ['nullable', 'boolean'],
        ]);
        unset($data['file'], $data['remove_file'], $data['amendment_reason']);
        $data['auto_renew'] = $request->boolean('auto_renew');
        $data['confidential'] = $request->boolean('confidential');

        /** @var Contract|null $existing */
        $existing = $request->route('contract');
        if (! $existing) {
            $data['status'] = 'draft'; // status then moves only through the lifecycle actions
        } else {
            // A portal e-signature carries a real timestamp; the date-only field must never overwrite it,
            // and an unchanged date must not truncate the time either.
            $sameDay = $existing->signed_at && ! empty($data['signed_at']) && $existing->signed_at->isSameDay(Carbon::parse($data['signed_at']));
            if ($existing->isSignedInPortal() || $sameDay) {
                unset($data['signed_at']);
            }
        }

        return $data;
    }

    private function storeFile(Request $request, Contract $contract): void
    {
        if ($request->boolean('remove_file') && $contract->file_path) {
            Storage::disk('local')->delete($contract->file_path);
            $contract->update(['file_path' => null]);
        }
        if ($request->hasFile('file')) {
            if ($contract->file_path) {
                Storage::disk('local')->delete($contract->file_path);
            }
            $contract->update(['file_path' => $request->file('file')->store('contracts', 'local')]);
        }
        if ($contract->signed_at && ! $contract->standard_terms) {
            $contract->freezeTerms();
        } elseif (! $contract->signed_at && $contract->standard_terms) {
            $contract->forceFill(['standard_terms' => null])->save(); // signature removed: terms are live again
        }
    }
}
