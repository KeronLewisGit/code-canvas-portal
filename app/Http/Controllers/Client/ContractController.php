<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\ContractInvoicing;
use App\Services\ContractLifecycle;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.contracts.index', ['contracts' => $request->user()->client->contracts()->visibleToClient()->get()]);
    }

    public function show(Request $request, Contract $contract): View
    {
        $this->authorizeView($contract);
        Audit::log($contract, 'viewed_by_client');

        return view('portal.contracts.show', ['contract' => $contract->load(['invoices' => fn ($q) => $q->where('status', '!=', 'draft'), 'payments'])]);
    }

    public function sign(Request $request, Contract $contract): View|RedirectResponse
    {
        $this->authorizeView($contract);
        if (! $request->user()->can('sign', $contract)) {
            return $this->notOpenForSignature($contract);
        }

        return view('portal.contracts.sign', ['contract' => $contract->load('client')]);
    }

    public function storeSignature(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeView($contract);
        if (! $request->user()->can('sign', $contract)) {
            return $this->notOpenForSignature($contract); // e.g. a double-submit after the first click already signed it
        }

        $data = $request->validate([
            'signed_name' => ['required', 'string', 'max:120'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
        ], ['agree.accepted' => 'Tick the box to confirm you have read the contract.', 'signature.required' => 'Please draw your signature in the box.']);

        $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        if ($png === false || ! str_starts_with($png, "\x89PNG")) {
            throw ValidationException::withMessages(['signature' => 'The signature image was not valid. Please draw it again.']);
        }

        $path = 'signatures/'.$contract->reference.'-'.Str::random(12).'.png';
        // The local disk does not throw, so a failed write must be caught here or the contract would be marked signed with no signature.
        abort_unless(Storage::disk('local')->put($path, $png), 500, 'The signature could not be saved. Please try again.');

        $signed = DB::transaction(function () use ($request, $contract, $data, $path) {
            $contract = Contract::lockForUpdate()->findOrFail($contract->id);
            if (! $contract->awaitingSignature()) {
                return false; // another request signed it between the check above and the lock
            }
            $contract->forceFill(['signature_path' => $path, 'signed_name' => $data['signed_name'], 'signed_ip' => $request->ip(), 'signed_by' => $request->user()->id, 'signed_at' => now()])->save();
            $contract->freezeTerms();
            if ($contract->status === 'approved') {
                app(ContractLifecycle::class)->transition($contract, 'activate', $request->user());
            }
            Audit::log($contract, 'signed_by_client', ['name' => $data['signed_name']]);

            return true;
        });
        if (! $signed) {
            Storage::disk('local')->delete($path);

            return $this->notOpenForSignature($contract->fresh());
        }
        $invoice = app(ContractInvoicing::class)->issueFirstInvoice($contract);
        app(Notifier::class)->contractSigned($contract->fresh(['client.users']), $invoice);

        // Next step for the client: the invoice that was just issued.
        if ($invoice) {
            return redirect()->route('portal.invoices.show', $invoice)->with('saved', 'Thank you. '.$contract->reference.' is signed. Your invoice is ready below, and you can pay it whenever you are ready.');
        }

        return redirect()->route('portal.contracts.show', $contract)->with('saved', 'Thank you. '.$contract->reference.' is signed. You can download the signed copy at any time.');
    }

    private function notOpenForSignature(Contract $contract): RedirectResponse
    {
        return redirect()->route('portal.contracts.show', $contract)->with('saved', $contract->isSigned() ? 'This contract is already signed.' : 'This contract is not open for signature.');
    }
}
