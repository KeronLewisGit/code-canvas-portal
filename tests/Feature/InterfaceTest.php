<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Server-rendered interface rules from the front-end review. */
class InterfaceTest extends TestCase
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

    public function test_the_dashboard_lists_next_steps_in_order_with_one_main_button(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Hosting renewal', 'issue_date' => now()->subMonth(), 'due_date' => now()->subWeek(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 300]]);
        Quotation::create(['client_id' => $this->client->id, 'title' => 'New logo', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        Contract::create(['client_id' => $this->client->id, 'title' => 'Booking site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);

        $html = $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertOk()
            ->assertSeeInOrder(['Needs your attention', 'Booking site', 'Read and sign', 'New logo', 'Review quote', 'Hosting renewal', 'Pay now'])->getContent();

        $this->assertSame(1, substr_count($html, 'class="btn btn-primary">Read and sign'), 'the first step is the main action');
        $this->assertStringContainsString('class="btn btn-light btn-sm">Pay now', $html);
    }

    public function test_an_up_to_date_client_is_told_they_are_caught_up(): void
    {
        Invoice::create(['client_id' => $this->client->id, 'title' => 'Paid', 'issue_date' => now(), 'status' => 'paid']);

        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertSee('You are all caught up');
    }

    public function test_the_create_login_form_has_real_labels(): void
    {
        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))
            ->assertSee('<label for="name" class="label">Name', false)
            ->assertSee('<label for="email" class="label">Email', false)
            ->assertSee('Leave blank to generate a secure one.');
    }

    public function test_actions_that_email_the_client_ask_first(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'draft']);

        $this->actingAs($this->admin)->get(route('admin.quotations.show', $quotation))->assertSee('data-confirm="Email this quotation to the client now?', false);
        $this->get(route('admin.quotations.create'))->assertSee('Save draft')->assertSee('data-confirm=', false)->assertDontSee('return confirm(', false);
    }

    public function test_signing_offers_a_typed_signature(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.sign', $contract))->assertSee('Type it instead');
    }
}
