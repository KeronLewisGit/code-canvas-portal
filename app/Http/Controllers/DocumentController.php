<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Support\Audit;
use Illuminate\View\View;

/** Print-ready quotation, invoice and contract pages. Access goes through the model policies. */
class DocumentController extends Controller
{
    public function quotation(Quotation $quotation): View
    {
        $this->authorizeView($quotation);

        return view('documents.quotation', ['doc' => $quotation->load('client', 'items')]);
    }

    public function invoice(Invoice $invoice): View
    {
        $this->authorizeView($invoice);

        return view('documents.invoice', ['doc' => $invoice->load('client', 'items', 'contract')]);
    }

    public function contract(Contract $contract): View
    {
        $this->authorizeView($contract);
        Audit::log($contract, 'document_viewed');

        return view('documents.contract', ['contract' => $contract->load('client', 'signer')]);
    }
}
