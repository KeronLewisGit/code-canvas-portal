<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Bugs found in the workflow review. */
class WorkflowFixesTest extends TestCase
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

    private function invoice(array $extra = [], float $amount = 500): Invoice
    {
        $invoice = Invoice::create($extra + ['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    public function test_a_contract_with_nothing_due_yet_is_not_shown_as_paid(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Hosting', 'type' => 'hosting', 'start_date' => now()->addMonth(), 'amount' => 100, 'billing_cycle' => 'yearly', 'status' => 'active']);

        $this->assertSame('not_due', $contract->paymentState());
        $this->actingAs($this->admin)->get(route('admin.contracts.show', $contract))->assertSee('Nothing due yet');
    }

    public function test_balance_outstanding_counts_issued_contracts_and_standalone_invoices(): void
    {
        Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        Contract::create(['client_id' => $this->client->id, 'title' => 'Hidden', 'type' => 'website', 'start_date' => now(), 'amount' => 9999, 'status' => 'approved']);
        $this->invoice([], 250);
        $this->invoice(['status' => 'draft'], 777);

        $this->assertEquals(1250, $this->client->outstanding());
        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertSee('TT$1,250.00');
    }

    public function test_clients_cannot_pay_more_than_is_owed(): void
    {
        $invoice = $this->invoice();
        $pay = fn (float $amount) => $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => $amount, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);

        $pay(600)->assertSessionHasErrors(['amount' => 'That is more than the TT$500.00 still owed on '.$invoice->reference.' (after payments already waiting to be verified).']);
        $pay(300)->assertSessionHasNoErrors();
        $pay(250)->assertSessionHasErrors('amount'); // 300 is already waiting to be verified
        $pay(200)->assertSessionHasNoErrors();
        $this->assertSame(2, Payment::count());
    }

    public function test_accepted_quotations_are_locked_and_invoiced_only_without_a_contract(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent']);
        $this->actingAs($this->admin)->post(route('admin.quotations.invoice', $quotation))->assertForbidden();

        $quotation->forceFill(['status' => 'accepted'])->save();
        $this->get(route('admin.quotations.show', $quotation))->assertSee('Create invoice')->assertDontSee(route('admin.quotations.edit', $quotation));
        $this->get(route('admin.quotations.edit', $quotation))->assertForbidden();
        $this->patch(route('admin.quotations.update', $quotation), ['title' => 'Changed'])->assertForbidden();

        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft']);
        $quotation->forceFill(['contract_id' => $contract->id])->save();
        $this->get(route('admin.quotations.show', $quotation))->assertDontSee('Create invoice');
        $this->post(route('admin.quotations.invoice', $quotation))->assertForbidden();
        $this->patch(route('admin.quotations.status', $quotation), ['status' => 'sent'])->assertSessionHasErrors('status');
    }

    public function test_inactive_clients_are_not_emailed_and_the_admin_is_told(): void
    {
        $this->client->update(['active' => false]);
        $invoice = $this->invoice(['status' => 'draft']);

        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'sent'])->assertSessionHas('warning', fn ($w) => str_contains($w, 'inactive'));

        Notification::assertNotSentTo($this->clientUser, PortalMail::class);
        $this->assertFalse(app(Notifier::class)->remind($invoice->fresh()->load('client.users')) !== null);
    }

    public function test_badges_use_the_configured_status_names(): void
    {
        $invoice = $this->invoice(['status' => 'partial']);

        $this->actingAs($this->clientUser)->get(route('portal.invoices.index'))->assertSee('Partly paid');
    }
}
