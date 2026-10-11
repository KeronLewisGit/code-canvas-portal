<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every receipt the client has been issued, newest first, to view or print. */
class ReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $receipts = $request->user()->client->payments()->whereNotNull('receipt_number')->with('invoice', 'contract')->reorder()->orderByDesc('verified_at')->orderByDesc('id')->get();

        return view('portal.receipts.index', ['receipts' => $receipts]);
    }
}
