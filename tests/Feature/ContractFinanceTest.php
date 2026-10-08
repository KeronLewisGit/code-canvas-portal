<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ContractFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
    }

    private function contract(array $attrs = []): Contract
    {
        return Contract::create($attrs + ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
    }

    private function invoice(Contract $c, float $total, string $status = 'sent', ?string $due = null): Invoice
    {
        $i = Invoice::create(['client_id' => $this->client->id, 'contract_id' => $c->id, 'title' => 'Inv', 'issue_date' => now()->subDays(20), 'due_date' => $due, 'status' => $status]);
        $i->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $total]]);

        return $i->fresh();
    }

    public function test_one_off_contract_values_are_derived_from_invoices_and_verified_payments(): void
    {
        $c = $this->contract(['amount' => 10000]);
        $i1 = $this->invoice($c, 5000, 'sent', now()->subDays(5)->toDateString());   // overdue
        $this->invoice($c, 2000, 'draft');                                           // draft: not counted
        $this->invoice($c, 3000, 'cancelled');                                       // cancelled: not counted
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $c->id, 'invoice_id' => $i1->id, 'amount' => 2000, 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $c->id, 'invoice_id' => $i1->id, 'amount' => 999, 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']); // pending: not collected
        $i1->refreshPaymentStatus();

        $s = $c->finance()->summary();
        $this->assertEquals(10000, $s['contracted_value']);
        $this->assertEquals(5000, $s['invoiced']);
        $this->assertEquals(2000, $s['collected']);
        $this->assertEquals(3000, $s['receivable']);
        $this->assertEquals(3000, $s['overdue']);
        $this->assertEquals(5000, $s['remaining_value']);
        $this->assertEquals(5000, $s['forecast_12m'], 'the un-invoiced remainder is forecast');
        $this->assertEquals(0, $s['monthly_recurring']);
    }

    public function test_fixed_term_monthly_contract_has_a_finite_value_and_a_forecast_that_stops_at_the_end(): void
    {
        $c = $this->contract(['billing_cycle' => 'monthly', 'amount' => 500, 'start_date' => now()->startOfMonth()->subMonths(2), 'end_date' => now()->startOfMonth()->addMonths(3)->subDay()]);
        $f = $c->finance();
        $this->assertSame(5, $f->cyclesInTerm());
        $this->assertEquals(2500, $f->contractedValue());
        $this->assertEquals(500, $f->monthlyRecurring());
        $forecast = $f->forecast(12, now()->startOfMonth());
        $this->assertEquals(1500, array_sum(array_column($forecast, 'amount')), 'three cycles remain: this month and the next two');
        $this->assertEquals(0, array_sum(array_column($forecast, 'cancellable')), 'fixed-term revenue is committed');
    }

    public function test_open_ended_contract_has_no_finite_value_and_revenue_beyond_notice_is_cancellable(): void
    {
        $c = $this->contract(['billing_cycle' => 'monthly', 'amount' => 600, 'start_date' => now()->startOfMonth(), 'end_date' => null, 'notice_period_days' => 45]);
        $f = $c->finance();
        $this->assertNull($f->contractedValue());
        $this->assertNull($f->remainingValue());
        $forecast = $f->forecast(12, now()->startOfMonth());
        $total = array_sum(array_column($forecast, 'amount'));
        $cancellable = array_sum(array_column($forecast, 'cancellable'));
        $this->assertEquals(7200, $total, '12 monthly cycles in the next 12 months');
        $this->assertEquals(6000, $cancellable, 'the two cycles starting within the 45-day notice window are committed');
    }

    public function test_quarterly_and_yearly_cycles_and_closed_contracts(): void
    {
        $q = $this->contract(['billing_cycle' => 'quarterly', 'amount' => 900, 'start_date' => now()->startOfMonth(), 'end_date' => now()->startOfMonth()->addYear()->subDay()]);
        $this->assertSame(4, $q->finance()->cyclesInTerm());
        $this->assertEquals(300, $q->monthlyEquivalent());

        $y = $this->contract(['billing_cycle' => 'yearly', 'amount' => 1200, 'start_date' => now()->startOfMonth(), 'end_date' => null]);
        $this->assertEquals(100, $y->monthlyEquivalent());
        $this->assertEquals(1200, array_sum(array_column($y->finance()->forecast(12, now()->startOfMonth()), 'amount')));

        $expired = $this->contract(['billing_cycle' => 'monthly', 'amount' => 100, 'start_date' => now()->subYear(), 'end_date' => now()->subDay()]);
        $this->assertSame('expired', $expired->effectiveStatus());
        $this->assertEquals(0, $expired->finance()->monthlyRecurring());
        $this->assertEquals(0, array_sum(array_column($expired->finance()->forecast(), 'amount')));

        $portfolio = ContractFinance::portfolio(collect([$q, $y, $expired]));
        $this->assertSame(2, $portfolio['live']);
        $this->assertSame(1, $portfolio['open_ended']);
        $this->assertEquals(3600 + $expired->finance()->contractedValue(), $portfolio['contracted_value'], 'closed contracts keep their historical contracted value');
        $this->assertEquals(400, $portfolio['monthly_recurring']);
        $this->assertCount(12, $portfolio['forecast_by_month']);
    }
}
