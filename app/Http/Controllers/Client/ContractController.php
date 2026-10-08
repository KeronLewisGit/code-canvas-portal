<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\ContractLifecycle;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.contracts.index', ['contracts' => $request->user()->client->contracts()->where('status', '!=', 'draft')->get()]);
    }

    public function show(Request $request, Contract $contract): View
    {
        $this->guard($request, $contract);
        Audit::log($contract, 'viewed_by_client');

        return view('portal.contracts.show', ['contract' => $contract->load(['invoices' => fn ($q) => $q->where('status', '!=', 'draft'), 'payments'])]);
    }

    public function sign(Request $request, Contract $contract): View|RedirectResponse
    {
        $this->guard($request, $contract);
        if (! $contract->awaitingSignature()) {
            return redirect()->route('portal.contracts.show', $contract)->with('saved', $contract->isSigned() ? 'This contract is already signed.' : 'This contract is not open for signature.');
        }

        return view('portal.contracts.sign', ['contract' => $contract->load('client')]);
    }

    public function storeSignature(Request $request, Contract $contract): RedirectResponse
    {
        $this->guard($request, $contract);
        abort_unless($request->user()->can('sign', $contract), 403, 'This contract is not open for signature.');

        $data = $request->validate([
            'signed_name' => ['required', 'string', 'max:120'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
        ], ['agree.accepted' => 'Tick the box to confirm you have read the contract.', 'signature.required' => 'Please draw your signature in the box.']);

        $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        abort_unless($png !== false && str_starts_with($png, "\x89PNG"), 422, 'The signature image was not valid.');

        $path = 'signatures/'.$contract->reference.'-'.Str::random(12).'.png';
        Storage::disk('local')->put($path, $png);

        $contract->forceFill(['signature_path' => $path, 'signed_name' => $data['signed_name'], 'signed_ip' => $request->ip(), 'signed_by' => $request->user()->id, 'signed_at' => now()])->save();
        $contract->freezeTerms();
        if ($contract->status === 'approved') {
            app(ContractLifecycle::class)->transition($contract, 'activate', $request->user());
        }
        Audit::log($contract, 'signed_by_client', ['name' => $data['signed_name']]);
        app(Notifier::class)->contractSigned($contract->fresh(['client.users']));

        return redirect()->route('portal.contracts.show', $contract)->with('saved', 'Thank you. '.$contract->reference.' is signed. You can download the signed copy at any time.');
    }

    private function guard(Request $request, Contract $contract): void
    {
        $this->authorizeView($contract);
    }
}
