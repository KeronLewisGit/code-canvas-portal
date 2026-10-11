<?php

namespace Tests\Feature;

use App\Jobs\IssueRecurringInvoices;
use App\Jobs\SendPaymentReminders;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\ContractInvoicing;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Package 3: billing automation. */
class AutomationPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2027-04-15 07:00');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function monthly(array $extra = []): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Support plan', 'type' => 'maintenance', 'start_date' => '2027-02-01', 'amount' => 500, 'currency' => 'TTD', 'billing_cycle' => 'monthly', 'payment_terms_days' => 7, 'status' => 'active', 'signed_at' => '2027-02-01']);
    }

    private function runJob(): void
    {
        (new IssueRecurringInvoices)->handle(app(ContractInvoicing::class), app(Notifier::class));
    }

    public function test_each_started_period_is_invoiced_once_and_the_client_is_emailed(): void
    {
        $contract = $this->monthly();
        app(ContractInvoicing::class)->issueFirstInvoice($contract); // February, at signing

        $this->runJob();
        $this->runJob(); // running again changes nothing

        $periods = $contract->invoices()->orderBy('period_start')->get();
        $this->assertSame(['2027-02-01', '2027-03-01', '2027-04-01'], $periods->map(fn ($i) => $i->period_start->toDateString())->all());
        $this->assertSame('Support plan: 1 Apr 2027 – 30 Apr 2027', $periods->last()->title);
        $this->assertSame('2027-04-22', $periods->last()->due_date->toDateString());
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 2);
    }

    public function test_a_contract_billed_by_hand_before_is_not_back_billed_and_continues_next_period(): void
    {
        $contract = $this->monthly();
        Invoice::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'April, by hand', 'issue_date' => '2027-04-02', 'status' => 'sent', 'currency' => 'TTD']);

        $this->runJob();
        $this->assertSame(0, $contract->invoices()->where('kind', 'cycle')->count(), 'April was already invoiced by hand, and February and March are not back-billed');

        Carbon::setTestNow('2027-05-01 07:00');
        $this->runJob();
        $this->assertSame(['2027-05-01'], $contract->invoices()->where('kind', 'cycle')->get()->map(fn ($i) => $i->period_start->toDateString())->all());
    }

    public function test_no_more_than_three_periods_per_run_and_nothing_after_the_end_date(): void
    {
        $long = $this->monthly(['start_date' => '2026-01-01', 'signed_at' => '2026-01-01']);
        app(ContractInvoicing::class)->issueFirstInvoice($long); // January 2026; February onwards are owed
        $ended = $this->monthly(['end_date' => '2027-02-28']);
        app(ContractInvoicing::class)->issueFirstInvoice($ended);

        $this->runJob();

        $this->assertSame(4, $long->invoices()->count(), 'the first plus at most three more per run');
        $this->assertSame(1, $ended->invoices()->count(), 'the term ended in February');
    }

    public function test_unsigned_or_one_off_contracts_are_not_billed_per_period(): void
    {
        $this->monthly(['signed_at' => null, 'status' => 'approved']);
        Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => '2027-01-01', 'amount' => 1000, 'billing_cycle' => 'one_time', 'status' => 'active', 'signed_at' => now()]);

        $this->runJob();

        $this->assertSame(0, Invoice::count());
    }

    public function test_due_on_receipt_invoices_are_reminded_after_two_weeks(): void
    {
        $recent = Invoice::create(['client_id' => $this->client->id, 'title' => 'Recent', 'issue_date' => now()->subDays(5), 'status' => 'sent', 'currency' => 'TTD']);
        $recent->syncItems([['description' => 'A', 'quantity' => 1, 'unit_price' => 100]]);
        $old = Invoice::create(['client_id' => $this->client->id, 'title' => 'Old', 'issue_date' => now()->subDays(20), 'status' => 'sent', 'currency' => 'TTD']);
        $old->syncItems([['description' => 'B', 'quantity' => 1, 'unit_price' => 100]]);

        (new SendPaymentReminders)->handle(app(Notifier::class));

        $this->assertNotNull($old->fresh()->reminded_at);
        $this->assertNull($recent->fresh()->reminded_at);
    }

    public function test_the_statement_shows_charges_payments_and_a_running_balance_to_its_own_client_only(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => '2027-03-01', 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Deposit', 'quantity' => 1, 'unit_price' => 1000]]);
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 400, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => '2027-03-10', 'status' => 'verified']);
        Invoice::create(['client_id' => $this->client->id, 'title' => 'Hidden draft', 'issue_date' => '2027-03-05', 'status' => 'draft', 'currency' => 'TTD']);

        $this->actingAs($this->clientUser)->get(route('documents.statement', $this->client))->assertOk()
            ->assertSeeInOrder(['Deposit', '1,000.00', 'Payment received', '400.00', 'Balance due', 'TT$600.00'])->assertDontSee('Hidden draft');
        $other = Client::create(['company' => 'Other', 'contact_name' => 'Bo', 'email' => 'bo@x.test']);
        $this->get(route('documents.statement', $other))->assertNotFound();
        $this->actingAs($this->admin)->get(route('documents.statement', $other))->assertOk()->assertSee('No invoices or payments yet.');
    }

    public function test_credit_on_a_contract_is_shown_to_the_client(): void
    {
        $contract = $this->monthly(['billing_cycle' => 'one_time', 'amount' => 1000]);
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'amount' => 300, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertSee('TT$300.00 in credit on this contract');
    }

    public function test_completing_a_contract_with_something_left_to_bill_says_so(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'currency' => 'TTD', 'billing_cycle' => 'one_time', 'status' => 'active', 'signed_at' => now(), 'deposit_percent' => 50]);
        app(ContractInvoicing::class)->issueFirstInvoice($contract);

        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'complete'])
            ->assertSessionHas('saved', fn ($m) => str_contains($m, 'TT$500.00 is not invoiced yet'));
        $this->get(route('admin.contracts.show', $contract))->assertSee('not invoiced yet')->assertSee('Create balance invoice');
    }
}
