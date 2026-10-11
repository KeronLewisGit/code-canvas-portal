<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PendingActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Menu bubbles count what the client still has to do (answer, sign, pay) and clear only when it is done. */
class PendingActionsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function counts(): array
    {
        app()->forgetScopedInstances();

        return app(PendingActions::class)->counts($this->client->fresh());
    }

    private function invoice(float $amount = 500): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    public function test_a_quotation_counts_until_it_is_answered_not_when_it_is_viewed(): void
    {
        $accept = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        $decline = Quotation::create(['client_id' => $this->client->id, 'title' => 'Logo', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Draft', 'issue_date' => now(), 'status' => 'draft']);
        $this->assertSame(2, $this->counts()['quotations']);

        $this->actingAs($this->clientUser)->get(route('portal.quotations.index'));
        $this->get(route('portal.quotations.show', $accept));
        $this->assertSame(2, $this->counts()['quotations'], 'looking is not answering');

        $this->patch(route('portal.quotations.answer', $accept), ['answer' => 'accept']);
        $this->assertSame(1, $this->counts()['quotations']);
        $this->patch(route('portal.quotations.answer', $decline), ['answer' => 'decline']);
        $this->assertSame(0, $this->counts()['quotations']);
    }

    public function test_a_contract_counts_until_it_is_signed(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'deposit_percent' => 0, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        Contract::create(['client_id' => $this->client->id, 'title' => 'Not sent', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'approved']);
        $this->assertSame(1, $this->counts()['contracts']);

        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract));
        $this->assertSame(1, $this->counts()['contracts']);

        $this->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==']);
        $this->assertSame(0, $this->counts()['contracts']);
    }

    public function test_payments_count_invoices_with_money_owed_until_a_payment_covers_them(): void
    {
        $invoice = $this->invoice(500);
        $this->assertSame(1, $this->counts()['payments']);

        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $invoice));
        $this->assertSame(1, $this->counts()['payments'], 'looking is not paying');

        $pay = fn (float $amount) => $this->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => $amount, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);
        $pay(200);
        $this->assertSame(1, $this->counts()['payments'], 'a part payment still leaves something to pay');
        $pay(300);
        $this->assertSame(0, $this->counts()['payments'], 'submitted payments cover it while we verify');
    }

    public function test_the_bubbles_sit_on_quotations_contracts_and_payments_only(): void
    {
        Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        $this->invoice();

        $html = $this->actingAs($this->clientUser)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<span class="nav-badge"'));
        $this->assertStringContainsString('(1 to answer)', $html);
        $this->assertStringContainsString('(1 to pay)', $html);
        $this->assertDoesNotMatchRegularExpression('#Invoices<span class="nav-badge"#', $html);
    }

    public function test_the_payment_form_offers_card_when_wipay_is_on(): void
    {
        config(['company.wipay.enabled' => true]);
        $invoice = $this->invoice(150);

        $this->actingAs($this->clientUser)->get(route('portal.payments.create', ['invoice' => $invoice->id]))->assertOk()
            ->assertSee('Pay now by card')->assertSee('Continue to secure card payment')
            ->assertViewHas('cardOptions', fn ($o) => $o[$invoice->id]['url'] === route('portal.invoices.card', $invoice) && $o[$invoice->id]['amount'] === 'TT$150.00');

        config(['company.wipay.enabled' => false]);
        $this->get(route('portal.payments.create'))->assertDontSee('Pay now by card');
    }
}
