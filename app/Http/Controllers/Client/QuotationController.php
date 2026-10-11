<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\QuotationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.quotations.index', ['quotations' => $request->user()->client->quotations()->where('status', '!=', 'draft')->get()]);
    }

    public function show(Request $request, Quotation $quotation): View
    {
        $this->authorizeView($quotation);

        return view('portal.quotations.show', ['quotation' => $quotation->load('items', 'contract')]);
    }

    public function answer(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeView($quotation);
        abort_unless($request->user()->can('answer', $quotation), 403, 'This quotation can no longer be answered.');
        $answer = $request->validate(['answer' => ['required', 'in:accept,decline']])['answer'];

        $contract = app(QuotationWorkflow::class)->answer($quotation, $answer === 'accept', $request->user());
        if ($answer === 'decline') {
            return back()->with('saved', 'Quotation declined. Tell us what would make it work.');
        }
        // When the contract went straight out for signature, take the client to it.
        if ($contract?->awaitingSignature()) {
            return redirect()->route('portal.contracts.show', $contract)->with('saved', 'Thank you for accepting. Your contract is ready: read it through, then sign it online.');
        }

        return back()->with('saved', 'Thank you for accepting. We are preparing your contract and will email you as soon as it is ready to sign.');
    }
}
