<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Services\ContractLifecycle;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function __construct(private ContractLifecycle $lifecycle) {}

    public function index(Request $request): View
    {
        $contracts = Contract::with('client')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('cycle'), fn ($q) => $q->where('billing_cycle', $request->cycle))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->q.'%')->orWhere('reference', 'like', '%'.$request->q.'%')))
            ->orderByRaw("case status when 'active' then 0 when 'approved' then 1 when 'draft' then 2 else 3 end")->latest('start_date')->paginate(25)->withQueryString();

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

        return redirect()->route('admin.contracts.show', $contract)->with('saved', 'Contract '.$contract->reference.' created.');
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
        $reason = $request->input('amendment_reason');
        $wasVersion = $contract->version;
        DB::transaction(function () use ($request, $contract, $data, $reason) {
            $this->lifecycle->amend($contract, $data, $request->user(), $reason);
            $this->storeFile($request, $contract);
        });
        $message = $contract->version > $wasVersion ? 'Contract amended. Version '.$wasVersion.' is kept on record.' : 'Contract updated.';

        return redirect()->route('admin.contracts.show', $contract)->with('saved', $message);
    }

    /** Send for signature, withdraw the request, or clear a signature so the client can re-sign. */
    public function signature(Request $request, Contract $contract): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:request,withdraw,clear']])['action'];

        // Preconditions come back as a form error on the contract page, like the lifecycle actions, not a bare 403.
        $problem = match ($action) {
            'request' => in_array($contract->status, ['draft', 'approved', 'active']) && ! $contract->isSigned() ? null : 'Only unsigned draft, approved or active contracts can be sent for signature.',
            'withdraw' => $contract->sent_for_signature_at && ! $contract->isSigned() ? null : 'There is no open signature request to withdraw.',
            default => $contract->isClosed() ? 'The signature on a '.strtolower($contract->statusName()).' contract is part of the record and cannot be cleared.' : ($contract->isSignedInPortal() ? null : 'This contract has no portal signature to clear.'),
        };
        if ($problem) {
            return back()->withErrors(['action' => $problem]);
        }

        match ($action) {
            'request' => $this->sendForSignature($contract, $request),
            'withdraw' => $contract->forceFill(['sent_for_signature_at' => null])->save(),
            default => $this->clearSignature($contract),
        };

        return back()->with('saved', match ($action) {
            'request' => 'The client has been emailed and can now sign '.$contract->reference.' in their portal. It activates when they sign.',
            'withdraw' => 'Signature request withdrawn.',
            default => 'Signature cleared. Send it for signature again when ready.',
        });
    }

    /** A draft is approved on the way out; the client then signs, which activates it. */
    private function sendForSignature(Contract $contract, Request $request): void
    {
        DB::transaction(function () use ($contract, $request) {
            if ($contract->status === 'draft') {
                $this->lifecycle->transition($contract, 'approve', $request->user());
            }
            $contract->forceFill(['sent_for_signature_at' => now()])->save();
            Audit::log($contract, 'sent_for_signature');
        });
        app(Notifier::class)->contractSentForSignature($contract->fresh(['client.users']));
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

        return back()->with('saved', 'Contract is now '.strtolower($contract->statusName()).'.');
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
