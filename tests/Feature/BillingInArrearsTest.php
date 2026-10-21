<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractInvoicing;
use App\Support\LedgerCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A recurring contract can be invoiced on the last day of each period instead of the first. */
class BillingInArrearsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->client = Client::create(['company' => 'Success Laventille Secondary School', 'contact_name' => 'The Principal', 'email' => 'office@slss.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(array $extra = []): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Website maintenance', 'type' => 'maintenance', 'start_date' => '2027-02-01', 'amount' => 650, 'billing_cycle' => 'monthly', 'bill_in_arrears' => true, 'currency' => 'TTD', 'status' => 'active', 'signed_at' => '2026-11-01 10:00:00']);
    }

    private function on(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 08:00:00'));
        LedgerCache::flush();
    }

    public function test_nothing_is_invoiced_when_an_arrears_contract_is_signed(): void
    {
        $this->on('2026-11-02');

        $this->assertNull(app(ContractInvoicing::class)->issueFirstInvoice($this->contract()));
        $this->assertSame(0, $this->client->invoices()->count());
    }

    public function test_the_same_contract_billed_in_advance_still_invoices_at_signing(): void
    {
        $this->on('2026-11-02');

        $invoice = app(ContractInvoicing::class)->issueFirstInvoice($this->contract(['bill_in_arrears' => false]));

        $this->assertNotNull($invoice);
        $this->assertEquals(650, (float) $invoice->total);
    }

    public function test_each_month_is_invoiced_on_its_last_day_and_only_once(): void
    {
        $contract = $this->contract();
        $invoicing = app(ContractInvoicing::class);

        $this->on('2027-02-27');
        $this->assertSame([], $invoicing->issueDuePeriodInvoices($contract));

        $this->on('2027-02-28');
        $issued = $invoicing->issueDuePeriodInvoices($contract);
        $this->assertCount(1, $issued);
        $this->assertSame('2027-02-01', $issued[0]->period_start->toDateString());
        $this->assertEquals(650, (float) $issued[0]->total);
        $this->assertStringContainsString('1 Feb 2027 – 28 Feb 2027', $issued[0]->title);
        $this->assertSame('2027-03-14', $issued[0]->due_date->toDateString());

        $this->assertSame([], $invoicing->issueDuePeriodInvoices($contract), 'a second run must not bill the month again');

        $this->on('2027-03-31');
        $this->assertCount(1, $invoicing->issueDuePeriodInvoices($contract));
        $this->assertSame(2, $contract->invoices()->count());
    }

    public function test_a_contract_signed_after_its_start_date_is_billed_for_the_months_that_have_ended(): void
    {
        $this->on('2027-04-15');

        $issued = app(ContractInvoicing::class)->issueDuePeriodInvoices($this->contract());

        $this->assertSame(['2027-02-01', '2027-03-01'], collect($issued)->map(fn ($i) => $i->period_start->toDateString())->all());
    }

    public function test_what_is_owed_and_the_next_billing_date_follow_the_end_of_each_month(): void
    {
        $contract = $this->contract();

        $this->on('2027-02-10');
        $this->assertSame(0.0, $contract->dueToDate());
        $this->assertSame('2027-02-28', $contract->nextDueDate()->toDateString());

        $this->on('2027-02-28');
        $this->assertSame(650.0, $contract->dueToDate());
        $this->assertSame('2027-03-31', $contract->nextDueDate()->toDateString());

        $this->on('2027-05-01');
        $this->assertSame(1950.0, $contract->dueToDate()); // Feb, Mar and Apr have ended: 3 x 650
    }

    public function test_the_last_period_ends_with_the_contract(): void
    {
        $contract = $this->contract(['end_date' => '2027-03-15']);

        $this->on('2027-03-10');
        $this->assertSame('2027-03-15', $contract->nextDueDate()->toDateString());
        $this->assertSame(650.0, $contract->dueToDate());

        $this->on('2027-03-15');
        $this->assertSame(1300.0, $contract->dueToDate());
        $this->assertNull($contract->nextDueDate());
        $this->assertCount(2, app(ContractInvoicing::class)->issueDuePeriodInvoices($contract));
    }

    public function test_the_forecast_places_each_charge_on_the_last_day_of_its_month(): void
    {
        $this->on('2027-02-10');
        $forecast = $this->contract()->finance()->forecast(3);

        $this->assertSame(['2027-02', '2027-03', '2027-04'], array_keys($forecast));
        $this->assertSame([650.0, 650.0, 650.0], array_column($forecast, 'amount'));
    }

    public function test_the_printed_contract_and_terms_say_end_of_month_billing(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $contract = $this->contract(['status' => 'draft', 'signed_at' => null]);

        $this->actingAs($admin)->get(route('documents.contract', $contract))->assertOk()
            ->assertSee('is invoiced at the end of that month')->assertDontSee('is payable in advance')->assertSee('Until ended by notice')->assertDontSee('Until the work is delivered')->assertSee('invoiced at the end of each month');
        $terms = implode(' ', $contract->standardTerms());
        $this->assertStringContainsString('invoiced at the end of the cycle', $terms);
        $this->assertStringNotContainsString('invoiced in advance', $terms);
    }

    public function test_the_admin_form_saves_the_setting_and_ignores_it_for_one_off_work(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $form = ['client_id' => $this->client->id, 'title' => 'Maintenance', 'type' => 'maintenance', 'start_date' => '2027-02-01', 'amount' => 650, 'billing_cycle' => 'monthly', 'currency' => 'TTD', 'payment_terms_days' => 14, 'bill_in_arrears' => '1'];

        $this->actingAs($admin)->post(route('admin.contracts.store'), $form)->assertRedirect();
        $this->assertTrue(Contract::sole()->bill_in_arrears);

        $this->actingAs($admin)->post(route('admin.contracts.store'), ['billing_cycle' => 'one_time', 'title' => 'Build'] + $form)->assertRedirect();
        $this->assertFalse(Contract::where('title', 'Build')->sole()->bill_in_arrears);
    }
}
