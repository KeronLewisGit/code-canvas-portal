<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now()])->save();
    }

    public function test_deactivated_client_is_logged_out_on_the_next_request(): void
    {
        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertOk();
        $this->client->update(['active' => false]);
        $this->actingAs($this->clientUser->fresh())->get(route('portal.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_editing_a_signed_contract_keeps_the_signature_timestamp(): void
    {
        $signedAt = now()->subDay()->setTime(15, 42);
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => $signedAt, 'signature_path' => 'signatures/x.png', 'signed_name' => 'Ann']);
        $this->actingAs($this->admin)->patch(route('admin.contracts.update', $contract), [
            'client_id' => $this->client->id, 'title' => 'Site (typo fixed)', 'type' => 'website', 'start_date' => now()->format('Y-m-d'), 'amount' => 1000, 'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 14, 'signed_at' => $signedAt->format('Y-m-d'),
        ])->assertRedirect();
        $this->assertTrue($contract->fresh()->signed_at->equalTo($signedAt), 'time of day survives a date-only form field');
    }

    public function test_a_manually_paid_invoice_is_locked_and_stays_paid(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Cash job', 'issue_date' => now(), 'status' => 'sent']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 200]]);
        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'paid']);
        $this->actingAs($this->admin)->patch(route('admin.invoices.update', $invoice), ['client_id' => $this->client->id, 'title' => 'Cash job (edited)', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD', 'status' => 'sent', 'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 200]]])->assertForbidden();
        $this->assertSame(['paid', 'Cash job'], [$invoice->fresh()->status, $invoice->fresh()->title]);
    }

    public function test_quotation_valid_until_today_can_still_be_accepted_and_invoice_due_today_is_not_overdue(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Q', 'issue_date' => now()->subWeek(), 'valid_until' => now(), 'status' => 'sent']);
        $this->assertTrue($quotation->canBeAnswered());
        $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept'])->assertRedirect();
        $this->assertSame('accepted', $quotation->fresh()->status);

        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'I', 'issue_date' => now()->subWeek(), 'due_date' => now(), 'status' => 'sent']);
        $this->assertSame('sent', $invoice->effectiveStatus());
    }

    public function test_closed_contracts_cannot_be_signed_or_sent_for_signature(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $terminated = Contract::create(['client_id' => $this->client->id, 'title' => 'T', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'terminated', 'sent_for_signature_at' => now()]);
        $this->assertFalse($terminated->awaitingSignature());
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $terminated), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $png])->assertRedirect(route('portal.contracts.show', $terminated));
        $this->assertFalse($terminated->fresh()->isSigned());
        $completed = Contract::create(['client_id' => $this->client->id, 'title' => 'C', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'completed']);
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $completed), ['action' => 'request'])->assertRedirect()->assertSessionHasErrors('action');
        $this->assertNull($completed->fresh()->sent_for_signature_at);
    }

    public function test_clearing_a_signature_also_unfreezes_the_terms(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'S', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'active', 'signed_at' => now(), 'signature_path' => 'signatures/x.png', 'standard_terms' => ['Old' => 'old text']]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $contract), ['action' => 'clear'])->assertRedirect();
        $this->assertNull($contract->fresh()->standard_terms);
        $this->assertArrayHasKey('Deposit and start of work', $contract->fresh()->standardTerms(), 'live terms apply again');
    }

    public function test_deleting_a_quotation_removes_its_line_items_and_linked_quotations_are_protected(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Q', 'issue_date' => now(), 'status' => 'draft']);
        $quotation->syncItems([['description' => 'A', 'quantity' => 1, 'unit_price' => 1], ['description' => 'B', 'quantity' => 1, 'unit_price' => 1]]);
        $this->assertSame(2, LineItem::count());
        $this->actingAs($this->admin)->delete(route('admin.quotations.destroy', $quotation))->assertRedirect();
        $this->assertSame(0, LineItem::count());

        $linked = Quotation::create(['client_id' => $this->client->id, 'title' => 'L', 'issue_date' => now(), 'status' => 'accepted']);
        Invoice::create(['client_id' => $this->client->id, 'quotation_id' => $linked->id, 'title' => 'I', 'issue_date' => now(), 'status' => 'sent']);
        $this->actingAs($this->admin)->delete(route('admin.quotations.destroy', $linked))->assertForbidden();
    }

    public function test_policies_hide_other_clients_records_with_404(): void
    {
        $other = Client::create(['company' => 'Other', 'contact_name' => 'Bob', 'email' => 'bob@other.test']);
        $invoice = Invoice::create(['client_id' => $other->id, 'title' => 'I', 'issue_date' => now(), 'status' => 'sent']);
        $quotation = Quotation::create(['client_id' => $other->id, 'title' => 'Q', 'issue_date' => now(), 'status' => 'sent']);
        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $invoice))->assertNotFound();
        $this->actingAs($this->clientUser)->get(route('documents.invoice', $invoice))->assertNotFound();
        $this->actingAs($this->clientUser)->get(route('documents.quotation', $quotation))->assertNotFound();
        $this->actingAs($this->clientUser)->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept'])->assertNotFound();
    }
}
