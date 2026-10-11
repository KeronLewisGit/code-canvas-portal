<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.invoices.index', ['invoices' => $request->user()->client->invoices()->where('status', '!=', 'draft')->get()]);
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $this->authorizeView($invoice);

        return view('portal.invoices.show', ['invoice' => $invoice->load('items', 'contract', 'payments')]);
    }
}
