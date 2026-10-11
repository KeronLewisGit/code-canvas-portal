<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Package 2 (race-safe payments) and Package 3 (credit notes, refunds, late fees). */
class AdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Setting::forget();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function invoice(float $amount = 1000, array $extra = []): Invoice
    {
        $invoice = Invoice::create($extra + ['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(10), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice->fresh();
    }

    public function test_a_credit_note_reduces_what_is_owed_but_is_not_cash_collected(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now()]);
        $invoice = $this->invoice(1000, ['contract_id' => $contract->id]);

        $this->actingAs($this->admin)->post(route('admin.invoices.credit', $invoice), ['amount' => 1500, 'reason' => 'Too much'])->assertSessionHasErrors('amount');
        $this->post(route('admin.invoices.credit', $invoice), ['amount' => 250, 'reason' => 'Goodwill for the delay'])->assertSessionHas('saved');

        $this->assertEquals(750, $invoice->fresh()->balance());
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEquals(750, $contract->fresh()->balance());
        $this->assertEquals(0, $contract->fresh()->finance()->collected(), 'a credit note is not money received');
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'Credit of TT$250.00') && in_array('Reason: Goodwill for the delay', $m->lines, true));
        $this->actingAs($this->clientUser)->get(route('documents.statement', $this->client))->assertSee('Credit note · Goodwill for the delay');
    }

    public function test_credit_on_a_contract_can_be_refunded_and_never_more_than_is_available(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now()]);
        $this->invoice(300, ['contract_id' => $contract->id]);
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $this->assertEquals(200, $contract->fresh()->unallocatedCredit());

        $this->actingAs($this->admin)->get(route('admin.contracts.show', $contract))->assertSee('Credit on account: TT$200.00');
        $this->post(route('admin.contracts.refund', $contract), ['refund_amount' => 250, 'refund_method' => 'bank transfer'])->assertSessionHasErrors('refund_amount');
        $this->post(route('admin.contracts.refund', $contract), ['refund_amount' => 200, 'refund_method' => 'bank transfer', 'refund_note' => 'Ref 991'])->assertSessionHas('saved');

        $this->assertEquals(0, $contract->fresh()->unallocatedCredit());
        $this->assertEquals(-200, (float) Payment::where('method', 'refund')->sole()->amount);
        $this->assertEquals(300, $contract->fresh()->finance()->collected(), 'collected is net of the refund');
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => $m->subject === 'Refund of TT$200.00');
        $this->actingAs($this->clientUser)->get(route('portal.payments.index'))->assertSee('−TT$200.00');
    }

    public function test_an_overpaid_standalone_invoice_can_be_refunded(): void
    {
        $invoice = $this->invoice(100);
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 150, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))->assertSee('Refund TT$50.00 overpaid');
        $this->post(route('admin.invoices.refund', $invoice), ['refund_amount' => 50, 'refund_method' => 'cash'])->assertSessionHas('saved');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(100, $invoice->fresh()->paidAmount());
    }

    public function test_a_late_fee_is_one_months_rate_as_its_own_invoice_at_most_every_30_days(): void
    {
        Setting::put('late_fee_monthly_percent', 2);
        $invoice = $this->invoice(1000);

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))->assertSee('Charge late fee (TT$20.00)');
        $this->post(route('admin.invoices.late-fee', $invoice))->assertSessionHas('saved');

        $fee = Invoice::where('kind', 'late_fee')->sole();
        $this->assertEquals(20, (float) $fee->total);
        $this->assertSame('sent', $fee->status);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, $fee->reference));

        $this->post(route('admin.invoices.late-fee', $invoice))->assertSessionHasErrors('late_fee');
        $this->post(route('admin.invoices.late-fee', $fee))->assertSessionHasErrors('late_fee');
        $this->travel(31)->days();
        $this->post(route('admin.invoices.late-fee', $invoice))->assertSessionHas('saved');
        $this->assertSame(2, Invoice::where('kind', 'late_fee')->count());
    }

    public function test_no_late_fee_when_not_overdue(): void
    {
        $invoice = $this->invoice(1000, ['due_date' => now()->addDays(5)]);

        $this->actingAs($this->admin)->post(route('admin.invoices.late-fee', $invoice))->assertSessionHasErrors('late_fee');
        $this->get(route('admin.invoices.show', $invoice))->assertDontSee('Charge late fee');
    }

    public function test_verifying_the_same_payment_twice_emails_the_client_once(): void
    {
        $invoice = $this->invoice(500);
        $payment = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']);

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));
        $this->patch(route('admin.payments.verify', $payment))->assertSessionHas('saved', 'This payment was already verified.');

        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
    }

    public function test_a_refused_payment_does_not_keep_its_uploaded_proof(): void
    {
        $invoice = $this->invoice(100);

        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 500, 'method' => 'bank_transfer', 'paid_on' => now()->format('Y-m-d'), 'proof' => UploadedFile::fake()->image('slip.png')])->assertSessionHasErrors('amount');

        $this->assertSame([], Storage::disk('local')->allFiles('payments'));
    }
}
