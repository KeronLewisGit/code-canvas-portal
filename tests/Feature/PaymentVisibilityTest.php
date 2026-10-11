<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Payment options appear only once the client has an issued contract or invoice with something owed. */
class PaymentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->user->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function invoice(string $status, float $amount = 500): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => $status, 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    private function contract(array $extra): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000]);
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAs($this->user)->get(route('portal.dashboard'))->assertOk();
    }

    private function assertNoPaymentOptions(): void
    {
        $this->dashboard()->assertDontSee('Submit a payment')->assertDontSee(route('portal.payments.index').'"', false);
        foreach (['portal.payments.index', 'portal.payments.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('portal.dashboard'))->assertSessionHas('warning');
        }
        $this->post(route('portal.payments.store'), ['amount' => 10, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertRedirect(route('portal.dashboard'));
        $this->assertSame(0, Payment::count());
    }

    public function test_a_brand_new_client_can_browse_but_sees_no_payment_options(): void
    {
        $this->dashboard()->assertSee('Your portal is ready')->assertDontSee('Balance outstanding');
        foreach (['portal.contracts.index', 'portal.quotations.index', 'portal.invoices.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->assertNoPaymentOptions();
    }

    public function test_drafts_unsent_contracts_and_quotations_alone_do_not_open_payments(): void
    {
        $this->invoice('draft');
        $this->contract(['status' => 'draft']);
        $this->contract(['status' => 'approved']); // approved by us but never sent to the client
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent']);

        $this->dashboard()->assertDontSee('Your portal is ready');
        $this->assertNoPaymentOptions();
    }

    public function test_a_sent_invoice_opens_payments(): void
    {
        $invoice = $this->invoice('sent');

        $this->dashboard()->assertSee('Submit a payment')->assertSee(route('portal.payments.index'))->assertSee('Pay now');
        $this->get(route('portal.payments.create', ['invoice' => $invoice->id]))->assertOk();
        $this->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 500, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertRedirect();
        $this->assertSame(1, Payment::count());
    }

    public function test_a_contract_sent_for_signature_opens_payments(): void
    {
        $this->contract(['status' => 'approved', 'sent_for_signature_at' => now()]);

        $this->dashboard()->assertSee('Submit a payment');
        $this->get(route('portal.payments.create'))->assertOk();
    }

    public function test_settled_clients_keep_their_payment_history_but_lose_the_submit_button(): void
    {
        $invoice = $this->invoice('sent');
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $invoice->refreshPaymentStatus();

        $this->dashboard()->assertSee(route('portal.payments.index'))->assertDontSee('Submit a payment')->assertDontSee('Pay now');
        $this->get(route('portal.payments.index'))->assertOk()->assertDontSee('Submit a payment');
        $this->get(route('portal.payments.create'))->assertRedirect(route('portal.dashboard'));
    }

    public function test_a_cancelled_invoice_cannot_be_paid_even_alongside_an_open_one(): void
    {
        $this->invoice('sent');
        $cancelled = $this->invoice('cancelled');

        $this->actingAs($this->user)->post(route('portal.payments.store'), ['invoice_id' => $cancelled->id, 'amount' => 500, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertSessionHasErrors('invoice_id');
        $this->assertSame(0, Payment::count());
    }
}
