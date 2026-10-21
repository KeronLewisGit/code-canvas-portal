<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Special terms on a contract, and drafting a prepared proposal onto a client's profile. */
class SpecialTermsAndProposalTest extends TestCase
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
        $this->client = Client::create(['company' => 'Mail Boxes Etc. Cayman Islands', 'contact_name' => 'Julie', 'email' => 'julie@mbe.test']);
        $this->clientUser = User::create(['name' => 'Julie', 'email' => 'julie@mbe.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function contractForm(array $extra = []): array
    {
        return $extra + ['client_id' => $this->client->id, 'title' => 'Managed plan', 'type' => 'maintenance', 'start_date' => now()->toDateString(), 'amount' => 100, 'billing_cycle' => 'monthly', 'currency' => 'USD', 'payment_terms_days' => 14, 'scope' => 'Hosting and support'];
    }

    public function test_special_terms_are_saved_and_printed_before_the_standard_terms(): void
    {
        $this->actingAs($this->admin)->post(route('admin.contracts.store'), $this->contractForm(['terms' => 'A handover fee of US$700 applies on exit.']))->assertRedirect();
        $contract = Contract::sole();

        $this->assertSame('A handover fee of US$700 applies on exit.', $contract->terms);
        $this->actingAs($this->admin)->get(route('documents.contract', $contract))
            ->assertOk()->assertSeeInOrder(['Special terms', 'A handover fee of US$700 applies on exit.', 'the special terms apply', 'Standard terms']);
    }

    public function test_a_contract_without_special_terms_prints_as_before(): void
    {
        $this->actingAs($this->admin)->post(route('admin.contracts.store'), $this->contractForm())->assertRedirect();

        $this->actingAs($this->admin)->get(route('documents.contract', Contract::sole()))->assertOk()->assertDontSee('Special terms')->assertSee('is payable in advance');
    }

    public function test_fees_section_defers_to_special_terms_instead_of_claiming_advance_billing(): void
    {
        $this->actingAs($this->admin)->post(route('admin.contracts.store'), $this->contractForm(['terms' => 'Billed at month end.']))->assertRedirect();

        $this->actingAs($this->admin)->get(route('documents.contract', Contract::sole()))->assertOk()
            ->assertSee('set out in the special terms in section 3')->assertDontSee('is payable in advance');
    }

    public function test_changing_special_terms_on_a_live_contract_creates_a_new_version(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Managed plan', 'type' => 'maintenance', 'start_date' => now(), 'amount' => 100, 'billing_cycle' => 'monthly', 'currency' => 'USD', 'status' => 'active', 'terms' => 'Old terms.']);

        $this->actingAs($this->admin)->put(route('admin.contracts.update', $contract), $this->contractForm(['terms' => 'New terms.', 'amendment_reason' => 'Agreed exit fee']))->assertRedirect();

        $contract->refresh();
        $this->assertSame(2, $contract->version);
        $this->assertSame('Old terms.', $contract->versions()->where('version', 1)->sole()->snapshot['terms']);
        $this->assertSame('New terms.', $contract->terms);
    }

    public function test_the_client_sees_special_terms_when_asked_to_sign(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Managed plan', 'type' => 'maintenance', 'start_date' => now(), 'amount' => 100, 'billing_cycle' => 'monthly', 'currency' => 'USD', 'status' => 'approved', 'sent_for_signature_at' => now(), 'terms' => 'Handover fee US$700.']);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.sign', $contract))->assertOk()->assertSee('Special terms')->assertSee('Handover fee US$700.');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertOk()->assertSee('Handover fee US$700.');
    }

    public function test_the_proposal_command_drafts_a_quotation_and_contract_without_sending_anything(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman'])->assertSuccessful();

        $quotation = Quotation::sole();
        $contract = Contract::sole();
        $this->assertSame('draft', $quotation->status);
        $this->assertNull($quotation->sent_at);
        $this->assertSame($contract->id, $quotation->contract_id);
        $this->assertSame($this->client->id, $contract->client_id);
        $this->assertEquals(100, (float) $quotation->total);
        $this->assertSame(['USD', 'monthly', 'draft', 30, 14], [$contract->currency, $contract->billing_cycle, $contract->status, $contract->notice_period_days, $contract->payment_terms_days]);
        $this->assertEquals(0, (float) $contract->deposit_percent);
        $this->assertStringContainsString('handover fee of US$700', $contract->terms);
        $this->assertNull($contract->sent_for_signature_at);
        Notification::assertNothingSent();
        // A draft contract is invisible to the client.
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertNotFound();
    }

    public function test_running_the_proposal_command_twice_does_not_duplicate_anything(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman'])->assertSuccessful();
        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman'])->assertFailed();

        $this->assertSame(1, Quotation::count());
        $this->assertSame(1, Contract::count());
    }

    public function test_the_proposal_command_needs_an_existing_client_or_explicit_creation(): void
    {
        $this->client->delete();
        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman'])->assertFailed();
        $this->assertSame(0, Client::count());

        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman', '--create-client' => true])->assertFailed();
        $this->assertSame(0, Client::count());

        $this->artisan('portal:draft-proposal', ['proposal' => 'mbe-cayman', '--create-client' => true, '--email' => 'julie@mbe.test'])->assertSuccessful();
        $this->assertSame('Mail Boxes Etc. Cayman Islands', Client::sole()->company);
        $this->assertSame(1, Contract::count());
    }
}
