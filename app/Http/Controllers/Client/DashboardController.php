<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $client = $request->user()->client;
        $contracts = $client->contracts()->whereIn('status', ['approved', 'active', 'completed'])->get();

        return view('portal.dashboard', [
            'client' => $client,
            'contracts' => $contracts,
            'outstanding' => Client::outstandingOf($contracts),
            'pendingPayments' => $client->payments()->pending()->get(),
            'openQuotes' => $client->quotations()->where('status', 'sent')->get()->filter->canBeAnswered(),
            'unpaidInvoices' => $client->invoices()->whereIn('status', ['sent', 'partial'])->get(),
        ]);
    }
}
