<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContractLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
    }

    public function test_clients_cannot_open_draft_documents_by_url(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Draft', 'issue_date' => now(), 'status' => 'draft']);
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Draft', 'issue_date' => now(), 'status' => 'draft']);
        $this->actingAs($this->clientUser)->get(route('documents.invoice', $invoice))->assertNotFound();
        $this->actingAs($this->clientUser)->get(route('documents.quotation', $quotation))->assertNotFound();
        $this->actingAs($this->admin)->get(route('documents.invoice', $invoice))->assertOk();
    }

    public function test_deactivated_clients_cannot_sign_in(): void
    {
        $this->client->update(['active' => false]);
        $this->post(route('login.attempt'), ['email' => 'ann@acme.test', 'password' => 'secret-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_mail_failure_does_not_undo_the_action(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP down'));
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'draft']);

        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'sent'])->assertRedirect()->assertSessionHas('warning');
        $this->assertSame('sent', $invoice->fresh()->status, 'the invoice is still marked sent');
    }

    public function test_drafts_edit_freely_and_versions_begin_after_approval(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft']);
        $lc = app(ContractLifecycle::class);
        $lc->amend($contract, ['amount' => 1200], $this->admin);
        $this->assertSame(1, $contract->fresh()->version);
        $this->assertSame(0, $contract->versions()->count());

        $lc->transition($contract, 'approve', $this->admin);
        $lc->amend($contract, ['amount' => 1500], $this->admin, 'Scope grew');
        $this->assertSame(2, $contract->fresh()->version);
        $this->assertSame(1, $contract->versions()->count());
    }

    public function test_settings_cache_resets_between_application_instances(): void
    {
        Setting::put('deposit_percent', 40);
        $this->assertEquals(40, Setting::get('deposit_percent'));
        Setting::forget();
        $this->assertEquals(40, Setting::get('deposit_percent'), 'reads back from the database after a cache reset');
    }

    public function test_expired_contracts_are_not_counted_as_active_anywhere(): void
    {
        Contract::create(['client_id' => $this->client->id, 'title' => 'Old', 'type' => 'hosting', 'start_date' => now()->subYear(), 'end_date' => now()->subDay(), 'amount' => 100, 'billing_cycle' => 'monthly', 'status' => 'active']);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Active contracts');
        $this->assertSame(0, Contract::live()->count());
        $this->actingAs($this->clientUser)->get(route('portal.contracts.index'))->assertOk()->assertSee('Expired')->assertDontSee('>Active<', false);
    }
}
