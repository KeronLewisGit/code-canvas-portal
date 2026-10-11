<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The client is emailed whenever a quotation, contract or invoice is issued to them, and never sees drafts. */
class DocumentIssuingTest extends TestCase
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

    private function documentInput(array $extra = []): array
    {
        return $extra + ['client_id' => $this->client->id, 'title' => 'Booking site', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD', 'items' => [['description' => 'Build', 'quantity' => 1, 'unit_price' => 5000]]];
    }

    private function contractInput(array $extra = []): array
    {
        return $extra + ['client_id' => $this->client->id, 'title' => 'Booking site', 'type' => 'website', 'start_date' => now()->format('Y-m-d'), 'amount' => 5000, 'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 14];
    }

    private function assertClientEmailed(string $subjectContains): void
    {
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, $subjectContains));
    }

    public function test_a_quotation_saved_as_draft_stays_private_until_sent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.quotations.store'), $this->documentInput())->assertRedirect();
        $quotation = Quotation::sole();
        $this->assertSame('draft', $quotation->status);
        Notification::assertNotSentTo($this->clientUser, PortalMail::class);
        $this->actingAs($this->clientUser)->get(route('portal.quotations.show', $quotation))->assertNotFound();

        $this->actingAs($this->admin)->put(route('admin.quotations.update', $quotation), $this->documentInput(['send' => '1']))->assertSessionHas('saved', 'Quotation updated and emailed to the client.');
        $this->assertSame('sent', $quotation->fresh()->status);
        $this->assertClientEmailed('is ready for you');
        $this->actingAs($this->clientUser)->get(route('portal.quotations.show', $quotation))->assertOk();
    }

    public function test_save_and_send_issues_a_quotation_in_one_step(): void
    {
        $this->actingAs($this->admin)->post(route('admin.quotations.store'), $this->documentInput(['send' => '1']));

        $this->assertNotNull(Quotation::sole()->sent_at);
        $this->assertClientEmailed('is ready for you');
    }

    public function test_save_and_send_issues_an_invoice_and_editing_a_sent_invoice_keeps_it_sent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), $this->documentInput(['status' => 'sent']));
        $invoice = Invoice::sole();
        $this->assertClientEmailed('Invoice '.$invoice->reference);

        $this->get(route('admin.invoices.edit', $invoice))->assertOk()->assertSee('<input type="hidden" name="status" value="sent">', false)->assertDontSee('Save draft');
        $this->put(route('admin.invoices.update', $invoice), $this->documentInput(['status' => 'sent', 'title' => 'Renamed']));
        $this->assertSame(['sent', 'Renamed'], [$invoice->fresh()->status, $invoice->fresh()->title]);
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
    }

    public function test_a_draft_contract_is_hidden_and_save_and_send_issues_it_for_signature(): void
    {
        $this->actingAs($this->admin)->post(route('admin.contracts.store'), $this->contractInput());
        $draft = Contract::sole();
        $this->actingAs($this->clientUser)->get(route('portal.contracts.index'))->assertDontSee('Booking site');
        $this->get(route('portal.contracts.show', $draft))->assertNotFound();

        $this->actingAs($this->admin)->put(route('admin.contracts.update', $draft), $this->contractInput(['send' => '1']));
        $draft->refresh();
        $this->assertSame('approved', $draft->status);
        $this->assertNotNull($draft->sent_for_signature_at);
        $this->assertClientEmailed('is ready to sign');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $draft))->assertOk()->assertSee('Booking site');
    }

    public function test_creating_a_contract_with_save_and_send_emails_the_client_to_sign(): void
    {
        $this->actingAs($this->admin)->post(route('admin.contracts.store'), $this->contractInput(['send' => '1']))->assertSessionHas('saved', fn ($m) => str_contains($m, 'sent to the client to sign'));

        $this->assertTrue(Contract::sole()->awaitingSignature());
        $this->assertClientEmailed('is ready to sign');
    }

    public function test_approving_a_contract_without_sending_keeps_it_hidden_from_the_client(): void
    {
        $contract = Contract::create($this->contractInput(['status' => 'draft']));
        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'approve']);
        $this->assertSame('approved', $contract->fresh()->status);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertNotFound();
        $this->get(route('portal.dashboard'))->assertDontSee('Booking site');
    }

    public function test_contracts_that_took_effect_stay_on_record_for_the_client(): void
    {
        $terminated = Contract::create($this->contractInput(['status' => 'terminated']));

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $terminated))->assertOk();
    }

    public function test_an_admin_recording_an_acceptance_runs_the_same_flow_as_the_client(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->admin)->patch(route('admin.quotations.status', $quotation), ['status' => 'accepted']);
        $this->assertSame('accepted', $quotation->fresh()->status);
        $this->assertNotNull($acceptedAt = $quotation->fresh()->accepted_at);
        $this->assertClientEmailed('accepted');
        Notification::assertNotSentTo($this->admin, PortalMail::class); // they did it themselves

        // Recording the same answer again changes nothing and sends nothing.
        $this->travel(1)->hours();
        $this->patch(route('admin.quotations.status', $quotation), ['status' => 'accepted']);
        $this->assertEquals($acceptedAt, $quotation->fresh()->accepted_at);
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
    }

    public function test_a_client_acceptance_tells_the_admin(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept'])->assertRedirect();

        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_starts_with($m->subject, 'Accepted'));
        $this->assertClientEmailed('accepted');
    }
}
