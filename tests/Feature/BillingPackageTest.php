<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentReminders;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\ContractLifecycle;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Package 2: billing correctness. */
class BillingPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function contract(array $extra = []): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'currency' => 'TTD', 'billing_cycle' => 'one_time', 'payment_terms_days' => 14, 'status' => 'active', 'signed_at' => now()]);
    }

    private function invoice(?Contract $contract, float $amount, array $extra = []): Invoice
    {
        $invoice = Invoice::create($extra + ['client_id' => $this->client->id, 'contract_id' => $contract?->id, 'title' => 'Work', 'issue_date' => now()->subDays(20), 'due_date' => now()->subDays(6), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice->fresh();
    }

    private function contractPayment(Contract $contract, float $amount): Payment
    {
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['contract_id' => $contract->id, 'amount' => $amount, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertSessionHasNoErrors();
        $payment = Payment::latest('id')->first();
        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));

        return $payment;
    }

    public function test_a_payment_on_the_contract_settles_its_invoice_and_stops_the_reminders(): void
    {
        $contract = $this->contract();
        $deposit = $this->invoice($contract, 500);

        $this->contractPayment($contract, 500);

        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertEquals(0, $deposit->fresh()->balance());
        $this->assertEquals(500, $contract->fresh()->balance());
        (new SendPaymentReminders)->handle(app(Notifier::class));
        Notification::assertNotSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_starts_with($m->subject, 'Reminder'));
    }

    public function test_extra_paid_on_a_contract_is_credit_that_settles_the_next_invoice(): void
    {
        $contract = $this->contract();
        $first = $this->invoice($contract, 300);

        $this->contractPayment($contract, 500);
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertEquals(200, $contract->fresh()->unallocatedCredit());

        $second = $this->invoice($contract, 400);
        $second->refreshPaymentStatus();
        $this->assertSame('partial', $second->fresh()->status);
        $this->assertEquals(200, $second->fresh()->balance());
    }

    public function test_marking_an_invoice_paid_records_a_payment_so_the_contract_agrees(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract, 1000);

        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'paid']);

        $this->assertEquals(0, $contract->fresh()->balance());
        $this->actingAs($this->clientUser)->get(route('portal.payments.index'))->assertSee('Settled outside the portal');
        $this->get(route('portal.payments.create'))->assertDontSee('Settled outside the portal');
    }

    public function test_currency_is_locked_once_money_is_involved(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract, 500);
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']);
        $base = ['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'Work', 'issue_date' => now()->format('Y-m-d'), 'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 500]]];

        $this->actingAs($this->admin)->put(route('admin.invoices.update', $invoice), $base + ['currency' => 'USD', 'status' => 'sent'])->assertSessionHasErrors('currency');
        $this->put(route('admin.invoices.update', $invoice), $base + ['currency' => 'TTD', 'status' => 'draft'])->assertSessionHasErrors('status');
        $this->post(route('admin.invoices.store'), $base + ['currency' => 'USD', 'status' => 'draft'])->assertSessionHasErrors(['currency' => 'Contract '.$contract->reference.' is in TTD. Invoice it in the same currency.']);
        $this->put(route('admin.contracts.update', $contract), ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now()->format('Y-m-d'), 'amount' => 1000, 'billing_cycle' => 'one_time', 'currency' => 'USD', 'payment_terms_days' => 14])->assertSessionHasErrors('currency');
    }

    public function test_admin_payments_must_be_dated_today_or_earlier_on_open_invoices_and_confirm_overpayment(): void
    {
        $invoice = $this->invoice(null, 500);
        $cancelled = $this->invoice(null, 500, ['status' => 'cancelled']);
        $record = fn (array $extra) => $this->actingAs($this->admin)->post(route('admin.payments.store'), $extra + ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);

        $record(['paid_on' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('paid_on');
        $record(['invoice_id' => $cancelled->id])->assertSessionHasErrors('invoice_id');
        $record(['amount' => 5000])->assertSessionHasErrors('amount');
        $record(['amount' => 600, 'confirm_overpayment' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());
    }

    public function test_cancelling_a_contract_voids_its_unpaid_invoices_but_keeps_any_with_payments(): void
    {
        $contract = $this->contract(['status' => 'approved', 'signed_at' => null, 'sent_for_signature_at' => now()]);
        $unpaid = $this->invoice($contract, 500);
        $withPayment = $this->invoice($contract, 300);
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'invoice_id' => $withPayment->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']);

        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'cancel'])->assertSessionHas('saved', fn ($m) => str_contains($m, '1 unpaid invoice was cancelled'));

        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertSame('sent', $withPayment->fresh()->status);
    }

    public function test_terminating_names_the_invoices_still_open(): void
    {
        $contract = $this->contract();
        $open = $this->invoice($contract, 500);

        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'terminate', 'reason' => 'Client closed'])->assertSessionHas('saved', fn ($m) => str_contains($m, 'Still open: '.$open->reference));
    }

    public function test_totals_are_kept_per_currency(): void
    {
        $this->invoice(null, 1000);
        $this->invoice(null, 300, ['currency' => 'USD']);

        $this->assertSame(['TTD' => 1000.0, 'USD' => 300.0], $this->client->dueNow());
        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertSee('TT$1,000.00 · US$300.00');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('TT$1,000.00 · US$300.00');
    }

    public function test_an_automatic_renewal_is_billed_and_keeps_its_deposit_and_term(): void
    {
        Carbon::setTestNow('2028-01-02 09:00');
        $old = $this->contract(['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'auto_renew' => true, 'deposit_percent' => 30, 'billing_cycle' => 'monthly', 'amount' => 200]);

        app(ContractLifecycle::class)->expireDue();

        $renewal = Contract::where('renewal_of_id', $old->id)->sole();
        $this->assertSame(['active', '2028-01-01', '2028-12-31'], [$renewal->status, $renewal->start_date->toDateString(), $renewal->end_date->toDateString()], 'a whole-year term stays whole in a leap year');
        $this->assertEquals(30, (float) $renewal->deposit_percent);
        $invoice = $renewal->invoices()->sole();
        $this->assertEquals(200, (float) $invoice->total);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, $invoice->reference));
        Carbon::setTestNow();
    }

    public function test_month_end_billing_cycles_are_counted_on_the_day_they_fall_due(): void
    {
        Carbon::setTestNow('2027-02-28 10:00');
        $contract = $this->contract(['start_date' => '2027-01-31', 'billing_cycle' => 'monthly', 'amount' => 100]);

        $this->assertSame(2, $contract->cyclesElapsed(), 'the 28 Feb cycle counts on 28 Feb');
        $this->assertSame('2027-03-31', $contract->nextDueDate()->toDateString());
        Carbon::setTestNow();
    }
}
