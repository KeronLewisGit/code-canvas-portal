<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Services\ContractInvoicing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every main page loads with real data, within a query budget, so a slow (N+1) page or a broken template is
 * caught by the tests rather than by a client. Budgets have headroom over today's counts for 5 contracts.
 */
class PagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_pages_load_within_their_query_budget(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        $user->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
        foreach (range(1, 5) as $n) {
            $contract = Contract::create(['client_id' => $client->id, 'title' => "Contract $n", 'type' => 'website', 'start_date' => now()->subMonths(2), 'amount' => 1000, 'billing_cycle' => $n % 2 ? 'monthly' : 'one_time', 'status' => 'active', 'signed_at' => now()]);
            $invoice = app(ContractInvoicing::class)->issueFirstInvoice($contract);
            Payment::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        }

        $budgets = [
            [$user, route('portal.dashboard'), 70],
            [$user, route('portal.invoices.index'), 30],
            [$user, route('portal.contracts.index'), 30],
            [$user, route('portal.payments.create'), 40],
            [$admin, route('admin.dashboard'), 55],
            [$admin, route('admin.invoices.index'), 30],
            [$admin, route('admin.payments.create'), 35],
            [$admin, route('admin.reports.revenue'), 35],
            [$admin, route('admin.clients.show', $client), 50],
        ];
        foreach ($budgets as [$who, $url, $max]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($who)->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertLessThanOrEqual($max, $count, "$url ran $count queries (budget $max).");
        }
    }
}
