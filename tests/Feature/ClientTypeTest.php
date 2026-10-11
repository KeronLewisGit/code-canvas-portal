<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A client is a company (with a contact person) or an individual (who is their own contact). */
class ClientTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
    }

    public function test_an_individual_needs_only_a_name_which_becomes_the_client_name(): void
    {
        $this->actingAs($this->admin)->post(route('admin.clients.store'), ['type' => 'individual', 'contact_name' => 'Ann Smith', 'email' => 'ann@example.test', 'active' => '1', 'create_login' => '1'])->assertRedirect();

        $client = Client::sole();
        $this->assertSame(['individual', 'Ann Smith', 'Ann Smith'], [$client->type, $client->company, $client->contact_name]);
        $this->assertSame('Ann Smith', $client->users()->sole()->name);
        $this->get(route('admin.clients.index'))->assertSee('Individual');
    }

    public function test_a_company_still_needs_its_name(): void
    {
        $this->actingAs($this->admin)->post(route('admin.clients.store'), ['type' => 'company', 'contact_name' => 'Ann', 'email' => 'ann@acme.test'])
            ->assertSessionHasErrors(['company' => 'Enter the company or trading name.']);

        $this->post(route('admin.clients.store'), ['type' => 'company', 'company' => 'Acme Ltd', 'contact_name' => 'Ann', 'email' => 'ann@acme.test'])->assertRedirect();
        $this->assertSame(['company', 'Acme Ltd'], [Client::sole()->type, Client::sole()->company]);
    }

    public function test_switching_a_company_to_an_individual_uses_the_person_as_the_name(): void
    {
        $client = Client::create(['company' => 'Acme Ltd', 'contact_name' => 'Ann Smith', 'email' => 'ann@acme.test']);

        $this->actingAs($this->admin)->patch(route('admin.clients.update', $client), ['type' => 'individual', 'contact_name' => 'Ann Smith', 'email' => 'ann@acme.test', 'active' => '1']);

        $this->assertSame(['individual', 'Ann Smith'], [$client->fresh()->type, $client->fresh()->company]);
    }

    public function test_documents_and_signing_read_naturally_for_an_individual(): void
    {
        $client = Client::create(['type' => 'individual', 'company' => 'Ann Smith', 'contact_name' => 'Ann Smith', 'email' => 'ann@example.test']);
        $user = User::create(['name' => 'Ann Smith', 'email' => 'ann@example.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        $user->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
        $contract = Contract::create(['client_id' => $client->id, 'title' => 'Portfolio site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        $invoice = Invoice::create(['client_id' => $client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent']);

        $this->actingAs($user)->get(route('portal.contracts.sign', $contract))->assertOk()->assertDontSee('on behalf of');
        $this->get(route('documents.contract', $contract))->assertOk()->assertDontSee('For Ann Smith');
        $this->assertSame(1, substr_count($this->get(route('documents.invoice', $invoice))->getContent(), 'Ann Smith'), 'the name is not repeated as a contact');
        $this->get(route('account'))->assertDontSee('Company:');
    }

    public function test_the_form_offers_both_types(): void
    {
        $this->actingAs($this->admin)->get(route('admin.clients.create'))->assertSee('A company or organisation')->assertSee('An individual');
    }
}
