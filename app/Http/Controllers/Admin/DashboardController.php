<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $active = Contract::with('client')->live()->get();

        return view('admin.dashboard', [
            'stats' => [
                'clients' => Client::where('active', true)->count(),
                'active_contracts' => $active->count(),
                'contract_value' => $active->where('billing_cycle', 'one_time')->sum('amount'),
                'monthly_recurring' => $active->sum(fn (Contract $c) => $c->monthlyEquivalent()),
                'outstanding' => $active->sum(fn (Contract $c) => $c->balance()),
                'pending_payments' => Payment::pending()->count(),
                'pending_amount' => (float) Payment::pending()->sum('amount'),
                'open_quotes' => Quotation::where('status', 'sent')->count(),
            ],
            'pending' => Payment::with('client', 'contract', 'invoice')->pending()->oldest()->get(),
            'ending' => $active->filter(fn (Contract $c) => $c->end_date && $c->end_date->between(now(), now()->addDays(30)))->sortBy('end_date'),
            'unsigned' => Contract::with('client')->whereIn('status', ['approved', 'active'])->whereNull('signed_at')->orderBy('sent_for_signature_at')->take(5)->get(),
            'overdue' => Invoice::with('client')->whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', now())->orderBy('due_date')->get(),
            'recentContracts' => Contract::with('client')->latest()->take(6)->get(),
            'recentPayments' => Payment::with('client')->where('status', 'verified')->latest('verified_at')->take(6)->get(),
        ]);
    }
}
