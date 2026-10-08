<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractSigningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $clientUser;

    private Contract $contract;

    /** A 1x1 transparent PNG. */
    private string $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        $this->contract = Contract::create(['client_id' => $client->id, 'title' => 'Website', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft', 'scope' => 'Build the site']);
    }

    public function test_client_cannot_sign_until_admin_sends_it(): void
    {
        $this->contract->update(['status' => 'active']);
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $this->png])->assertForbidden();
    }

    public function test_sending_for_signature_activates_a_draft_and_client_can_sign(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $this->contract), ['action' => 'request'])->assertRedirect();
        $this->contract->refresh();
        $this->assertSame('approved', $this->contract->status, 'sending for signature approves the draft');
        $this->assertTrue($this->contract->awaitingSignature());

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $this->contract))->assertOk()->assertSee('Sign now');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.sign', $this->contract))->assertOk()->assertSee('Draw your signature');

        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => 'Ann Example', 'agree' => 1, 'signature' => $this->png])->assertRedirect(route('portal.contracts.show', $this->contract));

        $this->contract->refresh();
        $this->assertSame('active', $this->contract->status, 'the client signature activates it');
        $this->assertTrue($this->contract->isSignedInPortal());
        $this->assertSame('Ann Example', $this->contract->signed_name);
        $this->assertSame($this->clientUser->id, $this->contract->signed_by);
        Storage::disk('local')->assertExists($this->contract->signature_path);

        // Signing twice is refused and the printable contract shows the signature.
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => 'Again', 'agree' => 1, 'signature' => $this->png])->assertForbidden();
        $this->actingAs($this->clientUser)->get(route('documents.contract', $this->contract))->assertOk()->assertSee('Signed electronically')->assertSee('data:image/png;base64');
    }

    public function test_signature_requires_name_agreement_and_a_png(): void
    {
        $this->contract->forceFill(['status' => 'active', 'sent_for_signature_at' => now()])->save();
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => '', 'signature' => 'data:text/plain;base64,aGk='])->assertSessionHasErrors(['signed_name', 'agree', 'signature']);
        $this->assertFalse($this->contract->fresh()->isSigned());
    }

    public function test_other_clients_cannot_sign_or_read_the_contract(): void
    {
        $this->contract->forceFill(['status' => 'active', 'sent_for_signature_at' => now()])->save();
        $other = User::create(['name' => 'Bob', 'email' => 'bob@other.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => Client::create(['company' => 'Other', 'contact_name' => 'Bob', 'email' => 'bob@other.test'])->id]);
        $this->actingAs($other)->get(route('portal.contracts.sign', $this->contract))->assertNotFound();
        $this->actingAs($other)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => 'Bob', 'agree' => 1, 'signature' => $this->png])->assertNotFound();
        $this->actingAs($other)->get(route('documents.contract', $this->contract))->assertNotFound();
    }

    public function test_admin_can_clear_a_signature(): void
    {
        $this->contract->forceFill(['status' => 'active', 'sent_for_signature_at' => now()])->save();
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $this->contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $this->png]);
        $path = $this->contract->fresh()->signature_path;

        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $this->contract), ['action' => 'clear'])->assertRedirect();
        $this->assertFalse($this->contract->fresh()->isSigned());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_contract_prints_standard_terms_with_this_contracts_values(): void
    {
        $this->contract->forceFill(['status' => 'active', 'sent_for_signature_at' => now(), 'payment_terms_days' => 21, 'billing_cycle' => 'monthly', 'amount' => 600, 'notice_period_days' => 45])->save();
        $terms = $this->contract->fresh()->standardTerms();
        $this->assertArrayHasKey('How payments are made and confirmed', $terms);
        $this->assertArrayHasKey('Recurring services', $terms);
        $this->assertStringContainsString('within 21 days', $terms['Invoicing and payment']);
        $this->assertStringContainsString('billed monthly at TT$600.00 per month', $terms['Invoicing and payment']);
        $this->assertStringContainsString("45 days' written notice", $terms['Recurring services']);
        $this->assertStringContainsString('verified it against its bank, cash or cheque records', $terms['How payments are made and confirmed']);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.sign', $this->contract))->assertOk()->assertSee('Standard terms')->assertSee('Limitation of liability');
        $this->actingAs($this->clientUser)->get(route('documents.contract', $this->contract))->assertOk()->assertSee('Standard terms')->assertSee('Deposit and start of work');

        $oneOff = Contract::create(['client_id' => $this->contract->client_id, 'title' => 'Fixed', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
        $this->assertArrayNotHasKey('Recurring services', $oneOff->standardTerms());
    }
}
