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
use Tests\TestCase;

/** Package 5: admin productivity and polish. */
class AdminPolishPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
    }

    public function test_quotations_invoices_and_payments_can_be_searched(): void
    {
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Booking site', 'issue_date' => now(), 'status' => 'draft']);
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Logo refresh', 'issue_date' => now(), 'status' => 'draft']);
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Hosting', 'issue_date' => now(), 'status' => 'sent']);
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 10, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending', 'reference' => 'TRF-7781']);

        $this->actingAs($this->admin)->get(route('admin.quotations.index', ['q' => 'logo']))->assertSee('Logo refresh')->assertDontSee('Booking site');
        $this->get(route('admin.quotations.index', ['q' => 'nothing-like-this']))->assertSee('Nothing matches these filters.');
        $this->get(route('admin.invoices.index', ['q' => $invoice->reference]))->assertSee('Hosting');
        $this->get(route('admin.payments.index', ['q' => 'TRF-7781']))->assertSee('TT$10.00');
        $this->get(route('admin.payments.index', ['q' => 'zzz']))->assertDontSee('TT$10.00');
    }

    public function test_contract_choices_follow_the_chosen_client(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'active']);

        $this->actingAs($this->admin)->get(route('admin.invoices.create'))->assertSee(":hidden=\"client && client !== '{$contract->client_id}'\"", false);
        $this->get(route('admin.quotations.create'))->assertSee('x-model="client"', false);
    }

    public function test_settled_invoices_offer_no_edit_and_sent_ones_ask_first(): void
    {
        $sent = Invoice::create(['client_id' => $this->client->id, 'title' => 'A', 'issue_date' => now(), 'status' => 'sent']);
        $paid = Invoice::create(['client_id' => $this->client->id, 'title' => 'B', 'issue_date' => now(), 'status' => 'paid']);

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $sent))->assertSee('The client has already received this invoice. Edit it anyway?');
        $this->get(route('admin.invoices.show', $paid))->assertDontSee(route('admin.invoices.edit', $paid));
        $this->get(route('admin.invoices.edit', $paid))->assertForbidden();
    }

    public function test_the_contract_page_puts_the_next_step_first_and_reminders_say_when_the_last_one_went(): void
    {
        $draft = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'draft']);
        $this->actingAs($this->admin)->get(route('admin.contracts.show', $draft))->assertSee('<button class="btn btn-primary">Send for signature</button>', false);

        $waiting = Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'approved', 'sent_for_signature_at' => now(), 'reminded_at' => now()->subDays(3)]);
        $this->get(route('admin.contracts.show', $waiting))->assertSee('The last one went 3 days ago.');
    }
}
