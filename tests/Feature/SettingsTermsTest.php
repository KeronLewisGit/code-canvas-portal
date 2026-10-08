<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTermsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::forget();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
    }

    public function test_defaults_come_from_config_until_edited_and_edits_flow_to_documents(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
        $this->assertArrayHasKey('Deposit and start of work', $contract->standardTerms());

        $this->actingAs($this->admin)->get(route('admin.settings.terms'))->assertOk()->assertSee('Contract terms')->assertSee('Invoice terms');
        $this->actingAs($this->admin)->put(route('admin.settings.terms.update'), ['group' => 'contract_terms', 'clauses' => [
            ['heading' => 'Deposit', 'text' => 'A {deposit}% deposit is due first.'],
            ['heading' => 'Payment', 'text' => 'Invoices are due within {payment_days} days.'],
        ]])->assertRedirect();
        $this->actingAs($this->admin)->put(route('admin.settings.rates'), ['deposit_percent' => 30, 'late_fee_monthly_percent' => 2])->assertRedirect();

        $terms = $contract->fresh()->standardTerms();
        $this->assertSame(['Deposit', 'Payment'], array_keys($terms));
        $this->assertSame('A 30% deposit is due first.', $terms['Deposit']);
        $this->assertSame('Invoices are due within 14 days.', $terms['Payment']);
        $this->actingAs($this->admin)->get(route('documents.contract', $contract))->assertOk()->assertSee('A 30% deposit is due first.')->assertDontSee('Limitation of liability');

        $this->actingAs($this->admin)->put(route('admin.settings.terms.update'), ['group' => 'invoice_terms', 'clauses' => [['heading' => 'Pay us', 'text' => 'Please pay promptly.']]])->assertRedirect();
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'A', 'issue_date' => now(), 'status' => 'sent']);
        $this->actingAs($this->admin)->get(route('documents.invoice', $invoice))->assertOk()->assertSee('Please pay promptly.')->assertDontSee('Governing law');

        $this->actingAs($this->admin)->delete(route('admin.settings.terms.reset'), ['group' => 'contract_terms'])->assertRedirect();
        $this->assertArrayHasKey('Limitation of liability', $contract->fresh()->standardTerms());
    }

    public function test_signed_contracts_keep_the_terms_they_were_signed_with(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        Storage::fake('local');
        $this->actingAs($user)->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $png])->assertRedirect();

        $frozen = $contract->fresh()->standard_terms;
        $this->assertNotNull($frozen);
        $this->assertArrayHasKey('Limitation of liability', $frozen);

        $this->actingAs($this->admin)->put(route('admin.settings.terms.update'), ['group' => 'contract_terms', 'clauses' => [['heading' => 'New rule', 'text' => 'Everything changed.']]])->assertRedirect();
        $this->assertArrayHasKey('Limitation of liability', $contract->fresh()->standardTerms(), 'signed contract still shows the original terms');
        $this->assertArrayNotHasKey('New rule', $contract->fresh()->standardTerms());

        $later = Contract::create(['client_id' => $this->client->id, 'title' => 'Later', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'draft']);
        $this->assertSame(['New rule'], array_keys($later->standardTerms()), 'new contracts use the edited terms');
    }

    public function test_clients_cannot_edit_terms(): void
    {
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->actingAs($user)->get(route('admin.settings.terms'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.terms.update'), ['group' => 'contract_terms', 'clauses' => []])->assertForbidden();
    }
}
