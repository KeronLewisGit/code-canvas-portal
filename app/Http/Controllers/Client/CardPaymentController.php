<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\WiPay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Sends the client to WiPay's secure page to pay an invoice by card. */
class CardPaymentController extends Controller
{
    public function start(Request $request, Invoice $invoice, WiPay $wipay): RedirectResponse
    {
        $this->authorizeView($invoice);
        try {
            return redirect()->away($wipay->start($invoice, $request->user()));
        } catch (RuntimeException $e) {
            return back()->withErrors(['card' => $e->getMessage()]);
        }
    }
}
