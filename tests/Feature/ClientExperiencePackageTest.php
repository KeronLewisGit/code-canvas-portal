<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Package 4: client experience. */
class ClientExperiencePackageTest extends TestCase
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

    private function invoice(): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Website deposit', 'issue_date' => now(), 'due_date' => now()->addDays(14), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => "Design\nTwo rounds of changes", 'quantity' => 1, 'unit_price' => 1200]]);

        return $invoice;
    }

    public function test_lists_have_a_phone_layout_of_stacked_cards(): void
    {
        $invoice = $this->invoice();

        $html = $this->actingAs($this->clientUser)->get(route('portal.invoices.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<ul class="space-y-3 sm:hidden">', $html);
        $this->assertSame(2, substr_count($html, $invoice->reference), 'once in the table, once in the phone card');
    }

    public function test_the_invoice_page_says_how_to_pay_and_summarises_the_document_for_phones(): void
    {
        config(['company.bank.account_number' => '7654321']);
        $invoice = $this->invoice();

        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $invoice))->assertOk()
            ->assertSee('How to pay')->assertSee('7654321')->assertSee('I have paid: submit the payment')
            ->assertSee('What it covers')->assertSee('Two rounds of changes');
    }

    public function test_the_payment_form_no_longer_promises_card_payments(): void
    {
        $this->invoice();

        $this->actingAs($this->clientUser)->get(route('portal.payments.create'))->assertOk()->assertDontSee('coming soon')->assertSee('x-show="!invoice"', false);
    }

    public function test_contract_emails_mention_a_deposit_only_when_one_is_taken(): void
    {
        $noDeposit = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'deposit_percent' => 0, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        $withDeposit = Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'deposit_percent' => 50, 'status' => 'approved', 'sent_for_signature_at' => now()]);

        app(Notifier::class)->contractSentForSignature($noDeposit->load('client.users'));
        app(Notifier::class)->contractSentForSignature($withDeposit->load('client.users'));

        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, $noDeposit->reference) && in_array('Read it in your portal, then sign electronically in under a minute. Work starts once it is signed.', $m->lines, true));
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, $withDeposit->reference) && str_contains(implode(' ', $m->lines), 'deposit is received'));
    }

    public function test_reversing_a_verification_needs_a_reason_the_client_is_told(): void
    {
        $invoice = $this->invoice();
        $payment = Payment::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => 1200, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'verified']);

        $this->actingAs($this->admin)->get(route('admin.payments.show', $payment))->assertSee('Reverse this verification')->assertDontSee('Reversed by');
        $this->patch(route('admin.payments.reject', $payment), ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->patch(route('admin.payments.reject', $payment), ['rejection_reason' => 'The bank returned the transfer.']);

        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => in_array('Reason: The bank returned the transfer.', $m->lines, true));
    }

    public function test_clearer_subjects_for_contract_updates_and_payment_receipts(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now(), 'signed_at' => now()]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $contract), ['action' => 'activate']);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_starts_with($m->subject, 'Contract '.$contract->reference.' is now active'));

        $invoice = $this->invoice();
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 200, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => $m->subject === 'We received your payment of TT$200.00');
    }
}
