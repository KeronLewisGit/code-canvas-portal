<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Website', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/portal')->assertRedirect('/login');
    }

    public function test_roles_are_kept_apart(): void
    {
        $this->actingAs($this->clientUser)->get('/admin')->assertForbidden();
        $this->actingAs($this->admin)->get('/portal')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Payments awaiting verification');
    }

    public function test_client_sees_only_their_own_contracts(): void
    {
        $other = Client::create(['company' => 'Other', 'contact_name' => 'Bob', 'email' => 'bob@other.test']);
        $foreign = Contract::create(['client_id' => $other->id, 'title' => 'Secret', 'type' => 'website', 'start_date' => now(), 'amount' => 5, 'status' => 'active']);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $this->contract))->assertOk()->assertSee('Website');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $foreign))->assertNotFound();
    }

    public function test_references_are_sequential_per_year(): void
    {
        $this->assertSame('CT-'.now()->year.'-001', $this->contract->reference);
        $next = Contract::create(['client_id' => $this->client->id, 'title' => 'Second', 'type' => 'design', 'start_date' => now(), 'amount' => 1, 'status' => 'draft']);
        $this->assertSame('CT-'.now()->year.'-002', $next->reference);
    }

    public function test_bank_transfer_requires_a_screenshot(): void
    {
        $this->actingAs($this->clientUser)
            ->post(route('portal.payments.store'), ['contract_id' => $this->contract->id, 'amount' => 500, 'method' => 'bank_transfer', 'paid_on' => now()->format('Y-m-d')])
            ->assertSessionHasErrors('proof');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_payment_needs_no_proof_and_waits_for_verification(): void
    {
        $this->actingAs($this->clientUser)
            ->post(route('portal.payments.store'), ['contract_id' => $this->contract->id, 'amount' => 400, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d'), 'reference' => 'Handed to Keron'])
            ->assertRedirect();

        $payment = Payment::first();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('unpaid', $this->contract->fresh()->paymentState());
    }

    public function test_verified_payment_marks_contract_and_invoice_paid(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'title' => 'Full', 'issue_date' => now(), 'status' => 'sent']);
        $invoice->syncItems([['description' => 'Build', 'quantity' => 1, 'unit_price' => 1000]]);

        $this->actingAs($this->clientUser)
            ->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 1000, 'method' => 'bank_transfer', 'paid_on' => now()->format('Y-m-d'), 'proof' => UploadedFile::fake()->image('transfer.png')])
            ->assertRedirect();

        $payment = Payment::first();
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertSame($this->contract->id, $payment->contract_id, 'contract is inferred from the invoice');

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment))->assertRedirect();

        $this->assertSame('paid', $this->contract->fresh()->paymentState());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_rejected_payment_does_not_count(): void
    {
        $payment = Payment::create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'amount' => 1000, 'method' => 'cheque', 'paid_on' => now(), 'status' => 'pending']);
        $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), ['rejection_reason' => 'Cheque bounced'])->assertRedirect();

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('unpaid', $this->contract->fresh()->paymentState());
        $this->actingAs($this->clientUser)->get(route('portal.payments.show', $payment))->assertOk()->assertSee('Cheque bounced');
    }

    public function test_proof_file_is_private_to_the_owner(): void
    {
        $payment = Payment::create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'amount' => 10, 'method' => 'bank_transfer', 'paid_on' => now(), 'status' => 'pending', 'proof_path' => UploadedFile::fake()->image('p.png')->store('payments', 'local')]);
        $stranger = User::create(['name' => 'S', 'email' => 's@x.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => Client::create(['company' => 'X', 'contact_name' => 'X', 'email' => 'x@x.test'])->id]);

        $this->actingAs($this->clientUser)->get(route('files.proof', $payment))->assertOk();
        $this->actingAs($this->admin)->get(route('files.proof', $payment))->assertOk();
        $this->actingAs($stranger)->get(route('files.proof', $payment))->assertNotFound();
    }

    public function test_admin_can_create_quotation_with_line_items_and_convert_it(): void
    {
        $this->actingAs($this->admin)->post(route('admin.quotations.store'), [
            'client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD', 'tax_rate' => 0,
            'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => 2000], ['description' => 'Build', 'quantity' => 2, 'unit_price' => 1500], ['description' => '', 'quantity' => 1, 'unit_price' => 0]],
        ])->assertRedirect();

        $quotation = Quotation::first();
        $this->assertSame(2, $quotation->items()->count());
        $this->assertEquals(5000, $quotation->total);

        $this->actingAs($this->admin)->post(route('admin.quotations.invoice', $quotation))->assertRedirect();
        $invoice = Invoice::first();
        $this->assertEquals(5000, $invoice->total);
        $this->assertSame($quotation->id, $invoice->quotation_id);
    }

    public function test_client_can_accept_a_sent_quotation(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'valid_until' => now()->addWeek(), 'status' => 'sent']);
        $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept'])->assertRedirect();
        $this->assertSame('accepted', $quotation->fresh()->status);
    }

    public function test_draft_documents_are_hidden_from_clients(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Draft', 'issue_date' => now(), 'status' => 'draft']);
        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $invoice))->assertNotFound();
        $this->actingAs($this->clientUser)->get(route('portal.invoices.index'))->assertDontSee('INV-');
    }

    public function test_recurring_contracts_track_what_is_due_to_date(): void
    {
        $monthly = Contract::create(['client_id' => $this->client->id, 'title' => 'Retainer', 'type' => 'retainer', 'start_date' => now()->subMonths(2)->subDays(3), 'amount' => 600, 'billing_cycle' => 'monthly', 'status' => 'active']);

        $this->assertTrue($monthly->isRecurring());
        $this->assertSame(3, $monthly->cyclesElapsed(), 'three monthly cycles have started');
        $this->assertEquals(1800, $monthly->dueToDate());
        $this->assertSame('TT$600.00 / month', $monthly->amountLabel());
        $this->assertSame('unpaid', $monthly->paymentState());

        Payment::create(['client_id' => $this->client->id, 'contract_id' => $monthly->id, 'amount' => 1800, 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $this->assertSame('paid', $monthly->fresh()->paymentState());
        $this->assertEquals(0, $monthly->fresh()->balance());
        $this->assertTrue($monthly->nextDueDate()->isFuture());

        $future = Contract::create(['client_id' => $this->client->id, 'title' => 'Later', 'type' => 'hosting', 'start_date' => now()->addMonth(), 'amount' => 100, 'billing_cycle' => 'yearly', 'status' => 'active']);
        $this->assertSame(0, $future->cyclesElapsed());
        $this->assertSame('paid', $future->paymentState(), 'nothing is due before the start date');
    }

    public function test_client_page_shows_payments_only_once_a_contract_is_invoiced(): void
    {
        $page = fn () => $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))->assertOk();

        $page()->assertDontSee('Record a payment');

        Invoice::create(['client_id' => $this->client->id, 'title' => 'Loose', 'issue_date' => now(), 'status' => 'sent']);
        $draft = Invoice::create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'draft']);
        $page()->assertDontSee('Record a payment');

        $draft->forceFill(['status' => 'sent'])->save();
        $page()->assertSee('Record a payment');
    }

    public function test_client_page_keeps_showing_payments_already_recorded(): void
    {
        Payment::create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'bank_transfer', 'paid_on' => now(), 'status' => 'verified']);

        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))->assertOk()->assertSee('Record a payment');
    }
}
