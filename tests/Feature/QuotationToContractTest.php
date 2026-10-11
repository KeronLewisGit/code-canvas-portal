<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\QuotationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Accepting a quotation generates its contract and, when the quotation says so, sends it for signature. */
class QuotationToContractTest extends TestCase
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
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann Smith', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function quotation(array $extra = []): Quotation
    {
        $q = Quotation::create($extra + ['client_id' => $this->client->id, 'title' => 'Support plan', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now(), 'currency' => 'TTD', 'contract_type' => 'maintenance', 'billing_cycle' => 'monthly', 'contract_term_months' => 12, 'auto_send_contract' => true]);
        $q->syncItems([['description' => "Monthly support\nUp to 5 hours", 'quantity' => 1, 'unit_price' => 1500], ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 300]]);

        return $q->fresh();
    }

    private function accept(Quotation $q)
    {
        return $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $q), ['answer' => 'accept']);
    }

    public function test_accepting_generates_the_contract_from_the_quotation_and_sends_it_for_signature(): void
    {
        $q = $this->quotation();

        $response = $this->accept($q);
        $contract = Contract::sole();
        $response->assertRedirect(route('portal.contracts.show', $contract))->assertSessionHas('saved', fn ($m) => str_contains($m, 'ready'));

        $this->assertSame($contract->id, $q->fresh()->contract_id);
        $this->assertSame(['Support plan', 'maintenance', 'monthly', 'TTD', 'Ann Smith', config('company.signatory')], [$contract->title, $contract->type, $contract->billing_cycle, $contract->currency, $contract->client_signatory, $contract->company_signatory]);
        $this->assertEquals(1800, (float) $contract->amount);
        $this->assertSame(now()->startOfDay()->addMonths(12)->subDay()->toDateString(), $contract->end_date->toDateString());
        $this->assertSame("- Monthly support\n  Up to 5 hours\n- Hosting", $contract->scope);
        $this->assertTrue($contract->awaitingSignature());
        $this->assertTrue($contract->activity()->where('action', 'created')->exists());

        // One email to the client (the contract, with a thank-you), one to the admin (accepted, with the contract).
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'ready to sign') && str_contains($m->lines[0], 'Thank you for accepting quotation '.$q->reference));
        Notification::assertSentToTimes($this->admin, PortalMail::class, 1);
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_starts_with($m->subject, 'Accepted') && str_contains($m->lines[1], 'sent to the client to sign'));

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertOk();
        $this->get(route('portal.quotations.show', $q))->assertSee('Read and sign the contract');
    }

    public function test_without_auto_send_the_contract_is_drafted_for_the_admin_to_review(): void
    {
        $q = $this->quotation(['auto_send_contract' => false]);

        $this->accept($q)->assertSessionHas('saved', fn ($m) => str_contains($m, 'preparing your contract'));

        $contract = Contract::sole();
        $this->assertSame('draft', $contract->status);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->lines[0], 'preparing your contract'));
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_contains($m->lines[1], 'Review it and send it for signature'));
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertNotFound();
    }

    public function test_legacy_quotations_without_contract_details_fall_back_to_a_one_off_draft(): void
    {
        $q = $this->quotation(['contract_type' => null, 'billing_cycle' => null, 'contract_term_months' => null, 'auto_send_contract' => false]);

        $this->accept($q);

        $contract = Contract::sole();
        $this->assertSame(['draft', 'one_time', array_key_first(config('company.contract_types'))], [$contract->status, $contract->billing_cycle, $contract->type]);
        $this->assertNull($contract->end_date);
    }

    public function test_accepting_twice_never_creates_a_second_contract(): void
    {
        $q = $this->quotation();
        $workflow = app(QuotationWorkflow::class);

        $workflow->answer($q, true, $this->clientUser);
        $this->assertNull($workflow->answer($q->fresh(), true, $this->clientUser));
        $this->actingAs($this->admin)->post(route('admin.quotations.contract', $q))->assertRedirect(route('admin.contracts.show', $q->fresh()->contract_id));

        $this->assertSame(1, Contract::count());
    }

    public function test_a_quotation_for_an_existing_contract_does_not_create_another(): void
    {
        $existing = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
        $q = $this->quotation(['contract_id' => $existing->id]);

        $this->accept($q)->assertSessionHas('saved', fn ($m) => str_contains($m, 'preparing'));

        $this->assertSame(1, Contract::count());
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_contains($m->lines[1], 'existing contract'));
    }

    public function test_an_admin_recording_acceptance_also_generates_and_sends_the_contract(): void
    {
        $q = $this->quotation();

        $this->actingAs($this->admin)->patch(route('admin.quotations.status', $q), ['status' => 'accepted'])
            ->assertSessionHas('saved', fn ($m) => str_contains($m, 'generated and sent to the client to sign'));

        $this->assertTrue(Contract::sole()->awaitingSignature());
        Notification::assertNotSentTo($this->admin, PortalMail::class);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'ready to sign'));
    }

    public function test_declining_creates_nothing(): void
    {
        $q = $this->quotation();

        $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $q), ['answer' => 'decline']);

        $this->assertSame(0, Contract::count());
        $this->assertSame('declined', $q->fresh()->status);
    }

    public function test_the_quotation_form_saves_the_contract_details(): void
    {
        $this->actingAs($this->admin)->post(route('admin.quotations.store'), [
            'client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD',
            'items' => [['description' => 'Build', 'quantity' => 1, 'unit_price' => 100]],
            'contract_type' => 'website', 'billing_cycle' => 'yearly', 'contract_term_months' => 24, 'auto_send_contract' => '1',
        ])->assertRedirect();

        $q = Quotation::sole();
        $this->assertSame(['website', 'yearly', 24, true], [$q->contract_type, $q->billing_cycle, $q->contract_term_months, $q->auto_send_contract]);
        $this->get(route('admin.quotations.show', $q))->assertSee('When the client accepts')->assertSee('24-month term');
    }
}
