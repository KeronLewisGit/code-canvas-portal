<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\ContractInvoicing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A signed contract issues its first invoice and tells the client they can pay. */
class ContractInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private string $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Setting::forget();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    private function contractAwaitingSignature(array $extra = []): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Booking site', 'type' => 'website', 'start_date' => now(), 'amount' => 5000, 'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 10, 'status' => 'approved', 'sent_for_signature_at' => now()]);
    }

    private function sign(Contract $contract)
    {
        return $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $this->png]);
    }

    public function test_signing_a_one_off_contract_issues_the_deposit_invoice_and_takes_the_client_to_it(): void
    {
        $contract = $this->contractAwaitingSignature();
        $quotation = Quotation::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'Booking site', 'issue_date' => now(), 'status' => 'accepted']);

        $response = $this->sign($contract);

        $invoice = Invoice::sole();
        $response->assertRedirect(route('portal.invoices.show', $invoice));
        $this->assertSame(['deposit', 'sent', $contract->id, $quotation->id, 'TTD'], [$invoice->kind, $invoice->status, $invoice->contract_id, $invoice->quotation_id, $invoice->currency]);
        $this->assertEquals(2500, (float) $invoice->total, 'the default 50% deposit');
        $this->assertEquals(0, (float) $invoice->tax_rate, 'the contract price already includes tax');
        $this->assertSame(now()->addDays(10)->toDateString(), $invoice->due_date->toDateString());
        $this->assertStringStartsWith('50% deposit: Booking site', $invoice->title);
        $this->assertTrue($contract->activity()->where('action', 'invoice_generated')->exists());

        // One email each, carrying the invoice.
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'Signed copy') && $m->actionUrl === route('portal.invoices.show', $invoice) && str_contains(implode(' ', $m->lines), 'deposit'));
        Notification::assertSentToTimes($this->admin, PortalMail::class, 1);
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_contains($m->lines[1], $invoice->reference));

        $this->get(route('portal.dashboard'))->assertSee('Submit a payment')->assertSee('Pay now');
        $this->get(route('portal.contracts.show', $contract))->assertSee('Deposit of TT$2,500.00 outstanding')->assertSee('Pay the deposit');
    }

    public function test_a_contract_level_deposit_overrides_the_setting_and_zero_means_one_full_invoice(): void
    {
        $this->sign($this->contractAwaitingSignature(['deposit_percent' => 30]));
        $this->assertEquals(1500, (float) Invoice::sole()->total);

        Invoice::query()->delete();
        $this->sign($this->contractAwaitingSignature(['deposit_percent' => 0]));
        $invoice = Invoice::sole();
        $this->assertSame('standard', $invoice->kind);
        $this->assertEquals(5000, (float) $invoice->total);
    }

    public function test_a_recurring_contract_invoices_its_first_period(): void
    {
        $this->sign($this->contractAwaitingSignature(['billing_cycle' => 'monthly', 'amount' => 1500]));

        $invoice = Invoice::sole();
        $this->assertSame('cycle', $invoice->kind);
        $this->assertEquals(1500, (float) $invoice->total);
        $this->assertStringStartsWith('First month', $invoice->title);
    }

    public function test_an_invoice_made_by_hand_first_means_none_is_generated(): void
    {
        $contract = $this->contractAwaitingSignature();
        Invoice::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'Manual', 'issue_date' => now(), 'status' => 'draft']);

        $this->sign($contract)->assertRedirect(route('portal.contracts.show', $contract));

        $this->assertSame(1, Invoice::count());
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_contains($m->lines[1], 'none was generated'));
    }

    public function test_it_never_issues_twice(): void
    {
        $contract = $this->contractAwaitingSignature();
        $this->sign($contract);

        $this->assertNull(app(ContractInvoicing::class)->issueFirstInvoice($contract->fresh()));
        $this->assertSame(1, Invoice::count());
    }

    public function test_unsigned_or_draft_contracts_get_no_invoice(): void
    {
        $this->assertNull(app(ContractInvoicing::class)->issueFirstInvoice($this->contractAwaitingSignature()));
        $this->assertNull(app(ContractInvoicing::class)->issueFirstInvoice($this->contractAwaitingSignature(['status' => 'draft', 'signed_at' => now()])));
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_paper_signature_recorded_by_an_admin_issues_and_emails_the_invoice(): void
    {
        $contract = $this->contractAwaitingSignature(['status' => 'active', 'sent_for_signature_at' => null]);

        $this->actingAs($this->admin)->put(route('admin.contracts.update', $contract), [
            'client_id' => $this->client->id, 'title' => 'Booking site', 'type' => 'website', 'start_date' => now()->format('Y-m-d'), 'amount' => 5000,
            'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 10, 'signed_at' => now()->format('Y-m-d'), 'amendment_reason' => 'Paper copy signed',
        ])->assertSessionHas('saved', fn ($m) => str_contains($m, 'was issued and emailed'));

        $invoice = Invoice::sole();
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'Invoice '.$invoice->reference));
    }

    public function test_activating_a_signed_contract_issues_the_invoice(): void
    {
        $contract = $this->contractAwaitingSignature(['signed_at' => now(), 'sent_for_signature_at' => null]);

        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'activate'])->assertSessionHas('saved', fn ($m) => str_contains($m, 'was issued'));

        $this->assertSame('deposit', Invoice::sole()->kind);
    }

    public function test_the_balance_invoice_is_prefilled_with_what_is_left_to_bill(): void
    {
        $contract = $this->contractAwaitingSignature();
        $this->sign($contract);
        $deposit = Invoice::sole();

        $this->actingAs($this->admin)->get(route('admin.contracts.show', $contract))->assertSee('Create balance invoice')->assertSee('Deposit outstanding');
        $this->get(route('admin.invoices.create', ['contract' => $contract->id, 'kind' => 'balance']))->assertOk()
            ->assertSee('Balance: Booking site')->assertSee('<input type="hidden" name="kind" value="balance">', false)
            ->assertViewHas('items', fn ($items) => (float) $items[0]['unit_price'] === 2500.0)->assertViewHas('invoice', fn ($i) => (float) $i->tax_rate === 0.0);

        Payment::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'invoice_id' => $deposit->id, 'amount' => 2500, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);
        $deposit->refreshPaymentStatus();
        $this->get(route('admin.contracts.show', $contract))->assertSee('Deposit received');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertSee('Deposit received');
    }
}
