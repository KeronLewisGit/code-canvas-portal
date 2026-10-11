<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Admin\ClientPreviewController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // Covers "remember me" sessions that never pass through the login form.
        if ($request->user()->needsWelcome() && ! ClientPreviewController::active()) {
            return redirect()->route('portal.welcome');
        }
        $client = $request->user()->client;
        $contracts = $client->contracts()->visibleToClient()->whereIn('status', ['approved', 'active', 'completed'])->get();

        $openQuotes = $client->quotations()->where('status', 'sent')->get()->filter->canBeAnswered();
        $unpaidInvoices = $client->payableInvoices()->sortBy(fn ($i) => [$i->effectiveStatus() === 'overdue' ? 0 : 1, $i->due_date?->timestamp ?? PHP_INT_MAX]);

        // One ordered list of what the client should do next: sign, then answer quotes, then pay (overdue first).
        $attention = collect()
            ->concat($contracts->filter->awaitingSignature()->map(fn ($c) => ['badge' => 'pending', 'badgeText' => 'To sign', 'title' => $c->title, 'meta' => $c->reference.' · '.$c->amountLabel(), 'action' => 'Read and sign', 'url' => route('portal.contracts.sign', $c)]))
            ->concat($openQuotes->map(fn ($q) => ['badge' => 'sent', 'badgeText' => 'To review', 'title' => $q->title, 'meta' => $q->reference.' · '.money($q->total, $q->currency).($q->valid_until ? ' · valid until '.$q->valid_until->format('j M Y') : ''), 'action' => 'Review quote', 'url' => route('portal.quotations.show', $q)]))
            ->concat($unpaidInvoices->map(fn ($i) => ['badge' => $i->effectiveStatus(), 'badgeText' => null, 'title' => $i->title, 'meta' => $i->reference.' · '.money($i->balance(), $i->currency).' due'.($i->due_date ? ' '.$i->due_date->format('j M Y') : ''), 'action' => 'Pay now', 'url' => route('portal.payments.create', ['invoice' => $i->id])]))
            ->values();

        return view('portal.dashboard', [
            'client' => $client,
            'canPay' => $client->hasPayables(),
            // Nothing issued yet (drafts are invisible to clients): show a welcome instead of empty totals.
            'brandNew' => $contracts->isEmpty() && $openQuotes->isEmpty() && ! $client->quotations()->where('status', '!=', 'draft')->exists() && ! $client->invoices()->where('status', '!=', 'draft')->exists() && ! $client->payments()->exists(),
            'contracts' => $contracts,
            'dueNow' => $client->dueNow(),
            'remaining' => $client->remainingOnContracts(),
            'pendingPayments' => $client->payments()->pending()->get(),
            'attention' => $attention,
            'recentInvoices' => $client->invoices()->where('status', '!=', 'draft')->take(5)->get(),
        ]);
    }
}
