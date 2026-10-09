<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Notifier;
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

        return view('portal.quotations.show', ['quotation' => $quotation->load('items')]);
    }

    public function answer(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeView($quotation);
        abort_unless($request->user()->can('answer', $quotation), 403, 'This quotation can no longer be answered.');
        $answer = $request->validate(['answer' => ['required', 'in:accept,decline']])['answer'];

        $quotation->forceFill($answer === 'accept' ? ['status' => 'accepted', 'accepted_at' => now()] : ['status' => 'declined', 'declined_at' => now()])->save();
        app(Notifier::class)->quotationAnswered($quotation->load('client.users'), $answer === 'accept');

        return back()->with('saved', $answer === 'accept' ? 'Thank you. We will be in touch with the contract and deposit invoice.' : 'Quotation declined. Tell us what would make it work.');
    }
}
