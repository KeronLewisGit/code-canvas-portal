<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Services\ContractFinance;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RevenueReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $contracts = Contract::with('client', 'invoices.payments', 'payments', 'renewals')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status), fn ($q) => $q->whereNotIn('status', ['draft', 'cancelled']))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('cycle'), fn ($q) => $q->where('billing_cycle', $request->cycle))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->client))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->currency))
            ->orderBy('end_date')->get();

        $days = (int) config('company.renewal_alert_days');
        $alerts = $contracts->filter(fn (Contract $c) => $c->isLive() && $c->end_date && $c->end_date->lte(now()->addDays($days)) && $c->renewals->isEmpty())->sortBy('end_date');
        $notices = $contracts->filter(fn (Contract $c) => $c->isLive() && $c->noticeDeadline() && $c->noticeDeadline()->between(now(), now()->addDays(30)))->sortBy(fn ($c) => $c->noticeDeadline());
        $unsigned = $contracts->filter(fn (Contract $c) => in_array($c->status, ['approved', 'active']) && ! $c->isSigned());

        return view('admin.reports.revenue', [
            'contracts' => $contracts,
            'portfolio' => ContractFinance::portfolio($contracts),
            'summaries' => $contracts->mapWithKeys(fn (Contract $c) => [$c->id => $c->finance()->summary()]),
            'alerts' => $alerts,
            'notices' => $notices,
            'unsigned' => $unsigned,
            'clients' => Client::orderBy('company')->get(['id', 'company']),
            'currencies' => Contract::query()->distinct()->pluck('currency'),
            'filters' => $request->only('status', 'type', 'cycle', 'client', 'currency'),
        ]);
    }
}
