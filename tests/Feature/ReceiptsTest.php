<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A receipt is issued and emailed for every payment confirmed, and the client can view and print them all. */
class ReceiptsTest extends TestCase
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

    private function invoice(float $amount = 1000): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Website deposit', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Deposit', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    private function receipts(): int
    {
        return Notification::sent($this->clientUser, PortalMail::class)->filter(fn (PortalMail $m) => str_starts_with($m->subject, 'Receipt '))->count();
    }

    public function test_verifying_a_payment_issues_a_numbered_receipt_and_emails_it_once(): void
    {
        $invoice = $this->invoice();
        $first = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 400, 'currency' => 'TTD', 'method' => 'bank_transfer', 'paid_on' => now(), 'status' => 'pending']);
        $second = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']);
        $this->assertNull($first->receipt_number, 'no receipt until it is confirmed');

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $first));
        $this->patch(route('admin.payments.verify', $second));
        $this->patch(route('admin.payments.verify', $first)); // again: nothing new

        $year = now()->format('Y');
        $this->assertSame(["RCT-$year-001", "RCT-$year-002"], [$first->fresh()->receipt_number, $second->fresh()->receipt_number]);
        $this->assertSame(2, $this->receipts());
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => $m->subject === "Receipt RCT-$year-001: TT\$400.00 received"
            && $m->actionUrl === route('documents.receipt', $first) && str_contains(implode(' ', $m->lines), 'The balance is now TT$600.00'));
    }

    public function test_payments_recorded_by_an_admin_and_invoices_marked_paid_get_receipts_too(): void
    {
        $recorded = $this->invoice(300);
        $this->actingAs($this->admin)->post(route('admin.payments.store'), ['client_id' => $this->client->id, 'invoice_id' => $recorded->id, 'amount' => 300, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);

        $markedPaid = $this->invoice(200);
        $this->patch(route('admin.invoices.status', $markedPaid), ['status' => 'paid']);

        $this->assertSame(2, Payment::whereNotNull('receipt_number')->count());
        $this->assertSame(2, $this->receipts());
    }

    public function test_credit_notes_and_refunds_never_get_a_receipt(): void
    {
        $invoice = $this->invoice(500);
        $this->actingAs($this->admin)->post(route('admin.invoices.credit', $invoice), ['amount' => 100, 'reason' => 'Goodwill']);
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => -50, 'currency' => 'TTD', 'method' => 'refund', 'paid_on' => now(), 'status' => 'verified']);

        $this->assertSame(0, Payment::whereNotNull('receipt_number')->count());
        $this->assertSame(0, $this->receipts());
    }

    public function test_the_client_can_view_and_print_their_receipts_and_only_theirs(): void
    {
        $invoice = $this->invoice(1000);
        $payment = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified', 'verified_at' => now()]);

        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertSee(route('portal.receipts.index'));
        $this->get(route('portal.receipts.index'))->assertOk()->assertSee($payment->receipt_number)->assertSee('View or print');
        $this->get(route('documents.receipt', $payment))->assertOk()->assertSee('RECEIPT')->assertSee($payment->receipt_number)->assertSee('TT$1,000.00')->assertSee($invoice->reference.' is paid in full');
        $this->get(route('portal.payments.show', $payment))->assertSee('View receipt');

        $other = Client::create(['company' => 'Other', 'contact_name' => 'Bo', 'email' => 'bo@x.test']);
        $intruder = User::create(['name' => 'Bo', 'email' => 'bo@x.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $other->id]);
        $intruder->forceFill(['welcomed_at' => now()])->save();
        $this->actingAs($intruder)->get(route('documents.receipt', $payment))->assertNotFound();
        $this->get(route('portal.dashboard'))->assertDontSee(route('portal.receipts.index'), false);
    }

    public function test_a_reversed_payment_keeps_its_receipt_number_but_shows_void(): void
    {
        $invoice = $this->invoice(500);
        $payment = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified', 'verified_at' => now()]);
        $number = $payment->receipt_number;

        $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), ['rejection_reason' => 'Cheque bounced']);

        $this->assertSame($number, $payment->fresh()->receipt_number);
        $this->actingAs($this->clientUser)->get(route('documents.receipt', $payment))->assertSee('VOID: this payment was reversed (Cheque bounced)');
        $this->get(route('portal.receipts.index'))->assertSee('Void');
        $this->get(route('portal.payments.show', $payment))->assertDontSee('View receipt');
    }

    public function test_an_admin_can_email_a_receipt_again(): void
    {
        $payment = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $this->invoice()->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);

        $this->actingAs($this->admin)->get(route('admin.payments.show', $payment))->assertSee('Email receipt again');
        $this->post(route('admin.payments.receipt', $payment))->assertSessionHas('saved', fn ($m) => str_contains($m, 'emailed'));

        $this->assertSame(2, $this->receipts());
    }
}
