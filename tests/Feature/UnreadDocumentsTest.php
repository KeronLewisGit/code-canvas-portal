<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Services\UnreadDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** "New" counts in the portal menu for quotations, contracts and invoices. */
class UnreadDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function counts(): array
    {
        app()->forgetScopedInstances();

        return app(UnreadDocuments::class)->counts($this->clientUser->fresh());
    }

    public function test_new_documents_are_counted_in_the_menu_until_opened(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Draft', 'issue_date' => now(), 'status' => 'draft']);
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        Contract::create(['client_id' => $this->client->id, 'title' => 'Hidden', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'approved']);

        $this->assertSame(['quotations' => 1, 'contracts' => 1, 'invoices' => 1], $this->counts(), 'drafts and unsent contracts are not counted');
        $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertSee('<span class="nav-badge" aria-hidden="true">1</span>', false)->assertSee('(1 new)');
        $this->get(route('portal.invoices.index'))->assertSee('<span class="new-tag">New</span>', false);

        $this->get(route('portal.quotations.show', $quotation))->assertOk();
        $this->get(route('portal.invoices.show', $invoice))->assertOk();
        $this->assertSame(['quotations' => 0, 'contracts' => 1, 'invoices' => 0], $this->counts());
        $this->get(route('portal.invoices.index'))->assertDontSee('<span class="new-tag">New</span>', false);
    }

    public function test_a_re_issued_contract_is_new_again_but_signing_it_does_not_count(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract));
        $this->assertSame(0, $this->counts()['contracts']);

        $this->travel(1)->hours();
        $contract->forceFill(['sent_for_signature_at' => now()])->save(); // sent again after an amendment
        $this->assertSame(1, $this->counts()['contracts']);

        $this->travel(1)->hours();
        $this->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==']);
        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(0, $this->counts()['contracts'], 'they just signed it, so it is not "new"');
    }

    public function test_an_admin_preview_never_marks_anything_read(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($admin)->post(route('admin.clients.preview', $this->client));
        $this->get(route('portal.invoices.show', $invoice))->assertOk();

        $this->assertSame(1, $this->counts()['invoices']);
    }

    public function test_the_payment_form_offers_card_when_wipay_is_on(): void
    {
        config(['company.wipay.enabled' => true]);
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 150]]);

        $this->actingAs($this->clientUser)->get(route('portal.payments.create', ['invoice' => $invoice->id]))->assertOk()
            ->assertSee('Pay now by card')->assertSee('Continue to secure card payment')
            ->assertViewHas('cardOptions', fn ($o) => $o[$invoice->id]['url'] === route('portal.invoices.card', $invoice) && $o[$invoice->id]['amount'] === 'TT$150.00');

        config(['company.wipay.enabled' => false]);
        $this->get(route('portal.payments.create'))->assertDontSee('Pay now by card');
    }
}
