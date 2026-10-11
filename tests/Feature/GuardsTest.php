<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Guards added by the silent-failure review: things that used to succeed quietly or fail with a bare error page. */
class GuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private Client $other;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->other = Client::create(['company' => 'Other Co', 'contact_name' => 'Bob', 'email' => 'bob@other.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
    }

    private function contract(array $extra = [], ?Client $client = null): Contract
    {
        return Contract::create($extra + ['client_id' => ($client ?? $this->client)->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
    }

    private function invoice(array $extra = [], float $amount = 500): Invoice
    {
        $invoice = Invoice::create($extra + ['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    private function invoiceInput(array $extra = []): array
    {
        return $extra + ['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD', 'status' => 'draft', 'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]]];
    }

    public function test_an_invoice_with_only_blank_line_items_is_a_validation_error_not_a_zero_invoice(): void
    {
        $this->actingAs($this->admin)->from(route('admin.invoices.create'))
            ->post(route('admin.invoices.store'), $this->invoiceInput(['items' => [['description' => '', 'quantity' => 1, 'unit_price' => 100]]]))
            ->assertRedirect(route('admin.invoices.create'))->assertSessionHasErrors('items');
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_cannot_be_linked_to_another_clients_contract(): void
    {
        $foreign = $this->contract([], $this->other);
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), $this->invoiceInput(['contract_id' => $foreign->id]))->assertSessionHasErrors('contract_id');
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_with_payments_cannot_be_deleted_or_moved_back_to_draft(): void
    {
        $invoice = $this->invoice();
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $invoice->refreshPaymentStatus();
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'draft'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->delete(route('admin.invoices.destroy', $invoice))->assertForbidden();
        $this->assertSame('paid', $invoice->fresh()->status);

        // Nor can it be handed to a different client while the payments still point at the old one.
        $this->actingAs($this->admin)->patch(route('admin.invoices.update', $invoice), $this->invoiceInput(['client_id' => $this->other->id, 'status' => 'sent']))->assertSessionHasErrors('client_id');
        $this->assertSame($this->client->id, $invoice->fresh()->client_id);
    }

    public function test_marking_a_partly_paid_invoice_paid_by_hand_is_refused(): void
    {
        $invoice = $this->invoice();
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $invoice->refreshPaymentStatus();
        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'paid'])->assertSessionHasErrors('status');
        $this->assertSame('partial', $invoice->fresh()->status);
    }

    public function test_overdue_filter_lists_sent_invoices_past_their_due_date(): void
    {
        $late = $this->invoice(['due_date' => now()->subDays(3)]);
        $this->invoice(['due_date' => now()->addDays(3)]);
        $this->actingAs($this->admin)->get(route('admin.invoices.index', ['status' => 'overdue']))->assertOk()->assertSee($late->reference)->assertDontSee(Invoice::where('id', '!=', $late->id)->value('reference'));
    }

    public function test_admin_payment_form_errors_come_back_as_field_errors(): void
    {
        $foreign = $this->contract([], $this->other);
        $usd = $this->invoice(['currency' => 'USD']);
        $base = ['client_id' => $this->client->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')];

        $this->actingAs($this->admin)->post(route('admin.payments.store'), $base + ['contract_id' => $foreign->id])->assertSessionHasErrors('contract_id');
        $this->actingAs($this->admin)->post(route('admin.payments.store'), $base + ['invoice_id' => $usd->id])->assertSessionHasErrors('currency');
        $this->assertSame(0, Payment::count());
    }

    public function test_verified_payments_cannot_be_deleted(): void
    {
        $payment = Payment::create(['client_id' => $this->client->id, 'amount' => 100, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $this->actingAs($this->admin)->delete(route('admin.payments.destroy', $payment))->assertForbidden();
        $payment->update(['status' => 'rejected']);
        $this->actingAs($this->admin)->delete(route('admin.payments.destroy', $payment))->assertRedirect(route('admin.payments.index'));
        $this->assertSame(0, Payment::count());
    }

    public function test_client_payment_takes_the_contract_from_the_invoice_and_refuses_a_mismatch(): void
    {
        $contractA = $this->contract();
        $contractB = $this->contract();
        $invoice = $this->invoice(['contract_id' => $contractA->id]);
        $base = ['amount' => 100, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')];

        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), $base + ['invoice_id' => $invoice->id, 'contract_id' => $contractB->id])->assertSessionHasErrors('contract_id');
        $this->assertSame(0, Payment::count());

        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), $base + ['invoice_id' => $invoice->id])->assertRedirect();
        $this->assertSame($contractA->id, Payment::first()->contract_id);
    }

    public function test_client_can_pay_a_deposit_on_an_approved_contract_once_it_is_sent(): void
    {
        $approved = $this->contract(['status' => 'approved']);
        // Approved but never sent to the client: hidden from them and not theirs to pay yet.
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $approved))->assertNotFound();
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['contract_id' => $approved->id, 'amount' => 500, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertRedirect(route('portal.dashboard'));
        $this->assertSame(0, Payment::count());

        $approved->forceFill(['sent_for_signature_at' => now()])->save();
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $approved))->assertOk()->assertSee('Submit a payment');
        $this->actingAs($this->clientUser)->get(route('portal.payments.create', ['contract' => $approved->id]))->assertOk()->assertSee('selected');
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['contract_id' => $approved->id, 'amount' => 500, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertRedirect();
        $this->assertSame(1, Payment::count());
    }

    public function test_client_proof_upload_is_stored_with_the_payment(): void
    {
        $contract = $this->contract();
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['contract_id' => $contract->id, 'amount' => 500, 'method' => 'bank_transfer', 'paid_on' => now()->format('Y-m-d'), 'proof' => UploadedFile::fake()->image('slip.png')])->assertRedirect();
        $path = Payment::first()->proof_path;
        $this->assertNotEmpty($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_quotation_converts_to_a_contract_only_once(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Q', 'issue_date' => now(), 'status' => 'accepted']);
        $quotation->syncItems([['description' => 'Build', 'quantity' => 1, 'unit_price' => 900]]);
        $this->actingAs($this->admin)->post(route('admin.quotations.contract', $quotation))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.quotations.contract', $quotation))->assertRedirect(route('admin.contracts.show', $quotation->fresh()->contract_id));
        $this->assertSame(1, Contract::count());
    }

    public function test_resaving_accepted_keeps_the_original_acceptance_time(): void
    {
        $acceptedAt = now()->subDays(3)->startOfSecond();
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Q', 'issue_date' => now(), 'status' => 'accepted', 'accepted_at' => $acceptedAt]);
        $this->actingAs($this->admin)->patch(route('admin.quotations.status', $quotation), ['status' => 'accepted'])->assertRedirect();
        $this->assertTrue($quotation->fresh()->accepted_at->equalTo($acceptedAt));
    }

    public function test_signature_on_a_closed_contract_cannot_be_cleared_and_renewals_are_guarded(): void
    {
        $completed = $this->contract(['status' => 'completed', 'signed_at' => now(), 'signature_path' => 'signatures/x.png', 'signed_name' => 'Ann', 'standard_terms' => ['A' => 'b']]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $completed), ['action' => 'clear'])->assertSessionHasErrors('action');
        $this->assertTrue($completed->fresh()->isSigned());
        $this->actingAs($this->admin)->get(route('admin.contracts.show', $completed))->assertOk()->assertDontSee('Clear signature');

        $draft = $this->contract(['status' => 'draft']);
        $this->actingAs($this->admin)->post(route('admin.contracts.renew', $draft))->assertSessionHasErrors('action');

        $active = $this->contract(['end_date' => now()->addMonth()]);
        $this->actingAs($this->admin)->post(route('admin.contracts.renew', $active))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.contracts.renew', $active))->assertRedirect();
        $this->assertSame(1, $active->renewals()->count());
    }

    public function test_version_route_rejects_non_numeric_versions(): void
    {
        $contract = $this->contract();
        $this->actingAs($this->admin)->get('/admin/contracts/'.$contract->id.'/versions/abc')->assertNotFound();
    }

    public function test_duplicate_clause_headings_are_refused(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.terms.update'), ['group' => 'contract_terms', 'clauses' => [['heading' => 'Payment', 'text' => 'a'], ['heading' => 'payment', 'text' => 'b']]])->assertSessionHasErrors('clauses.1.heading');
    }

    public function test_reminder_to_a_client_with_no_email_is_not_recorded_as_sent(): void
    {
        $this->client->update(['email' => '']);
        $this->clientUser->delete();
        $invoice = $this->invoice(['due_date' => now()->subDay()]);
        $this->actingAs($this->admin)->post(route('admin.invoices.remind', $invoice))->assertRedirect()->assertSessionHas('warning')->assertSessionMissing('saved');
        $this->assertNull($invoice->fresh()->reminded_at);
        $this->assertDatabaseMissing('activity_logs', ['subject_id' => $invoice->id, 'action' => 'reminder_sent']);
        $this->assertNull(app(Notifier::class)->remind($invoice));
    }

    public function test_mail_warnings_are_shown_to_admins_only(): void
    {
        $this->client->update(['email' => '']);
        $contract = $this->contract(['status' => 'approved', 'sent_for_signature_at' => now()]);
        // Admin mail goes out fine; the client's own copy cannot be delivered, but the client must not see a MAIL warning.
        $this->clientUser->delete();
        $signer = User::create(['name' => 'Ann', 'email' => 'ann2@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->actingAs($signer)->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='])
            ->assertRedirect()->assertSessionMissing('warning');
    }

    public function test_promoting_a_client_login_to_admin_needs_force(): void
    {
        $this->artisan('portal:admin', ['--email' => 'ann@acme.test', '--password' => 'a-long-password-123'])->assertFailed();
        $this->assertSame('client', $this->clientUser->fresh()->role);
        $this->artisan('portal:admin', ['--email' => 'ann@acme.test', '--password' => 'a-long-password-123', '--force' => true])->assertSuccessful();
        $this->assertSame('admin', $this->clientUser->fresh()->role);
    }

    public function test_totals_are_stored_rounded(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Odd', 'issue_date' => now(), 'status' => 'draft', 'tax_rate' => 12.5]);
        $invoice->syncItems([['description' => 'a', 'quantity' => 3, 'unit_price' => 0.1], ['description' => 'b', 'quantity' => 1, 'unit_price' => 0.2]]);
        $raw = \DB::table('invoices')->where('id', $invoice->id)->first();
        $this->assertSame('0.5', (string) round((float) $raw->subtotal, 10));
        $this->assertSame('0.56', (string) round((float) $raw->total, 10));
    }
}
