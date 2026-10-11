<?php

namespace Tests\Feature;

use App\Models\CardCheckout;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Card payments through WiPay's hosted checkout (sandbox credentials). */
class WiPayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['company.wipay.enabled' => true, 'company.wipay.account_number' => '1234567890', 'company.wipay.api_key' => '123', 'company.wipay.environment' => 'sandbox']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 2500, 'status' => 'active', 'signed_at' => now()]);
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'Deposit', 'issue_date' => now(), 'due_date' => now()->addDays(14), 'status' => 'sent', 'currency' => 'TTD']);
        $this->invoice->syncItems([['description' => 'Deposit', 'quantity' => 1, 'unit_price' => 1250]]);
    }

    private function startCheckout(): CardCheckout
    {
        Http::fake(['tt.wipayfinancial.com/*' => Http::response(['url' => 'https://tt.wipayfinancial.com/plugins/payments/checkout/abc123'])]);
        $this->actingAs($this->clientUser)->post(route('portal.invoices.card', $this->invoice))->assertRedirect('https://tt.wipayfinancial.com/plugins/payments/checkout/abc123');

        return CardCheckout::sole();
    }

    private function wipayResult(CardCheckout $checkout, array $override = []): array
    {
        $tx = $override['transaction_id'] ?? '100-1-12345';
        $total = $override['total'] ?? '1250.00';

        return $override + ['status' => 'success', 'order_id' => $checkout->order_id, 'transaction_id' => $tx, 'total' => $total, 'currency' => 'TTD', 'hash' => md5($tx.$total.'123'), 'message' => 'Approved'];
    }

    public function test_the_invoice_offers_card_payment_and_starts_a_wipay_checkout_for_the_balance(): void
    {
        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $this->invoice))->assertSee('Pay TT$1,250.00 by card')->assertSee('Or pay by bank transfer');

        $checkout = $this->startCheckout();

        $this->assertEquals(1250, (float) $checkout->amount);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://tt.wipayfinancial.com/plugins/payments/request'
            && $r['account_number'] === '1234567890' && $r['environment'] === 'sandbox' && $r['total'] === '1250.00' && $r['currency'] === 'TTD'
            && $r['order_id'] === $checkout->order_id && $r['method'] === 'credit_card' && $r['response_url'] === route('wipay.return') && $r['fee_structure'] === 'merchant_absorb' && preg_match('/^[A-Za-z0-9_-]+$/', $r['origin']) === 1);
    }

    public function test_a_verified_success_records_the_payment_once_and_settles_the_invoice(): void
    {
        $checkout = $this->startCheckout();

        $this->get(route('wipay.return', $this->wipayResult($checkout)))->assertRedirect(route('portal.invoices.show', $this->invoice))->assertSessionHas('saved', fn ($m) => str_contains($m, 'went through'));
        $this->get(route('wipay.return', $this->wipayResult($checkout)));
        $this->post(route('wipay.return'), $this->wipayResult($checkout), ['Accept' => 'application/json']); // WiPay's own notification

        $payment = Payment::sole();
        $this->assertSame(['card', 'verified', 'WiPay 100-1-12345'], [$payment->method, $payment->status, $payment->reference]);
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame('succeeded', $checkout->fresh()->status);
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_starts_with($m->subject, 'Card payment'));
    }

    public function test_a_forged_or_mismatched_result_records_nothing(): void
    {
        $checkout = $this->startCheckout();

        $this->get(route('wipay.return', $this->wipayResult($checkout, ['hash' => md5('100-1-123451250.00wrong-key')])))->assertSessionHas('warning', fn ($m) => str_contains($m, 'could not confirm'));
        $this->get(route('wipay.return', $this->wipayResult($checkout, ['total' => '1.00'])));
        $this->get(route('wipay.return', $this->wipayResult($checkout, ['currency' => 'USD'])));
        $this->get(route('wipay.return', $this->wipayResult($checkout, ['order_id' => 'INV-NOPE'])));

        $this->assertSame(0, Payment::count());
        $this->assertSame('started', $checkout->fresh()->status);
    }

    public function test_a_declined_card_records_nothing_and_says_so(): void
    {
        $checkout = $this->startCheckout();

        $this->get(route('wipay.return', ['status' => 'failed', 'order_id' => $checkout->order_id, 'message' => 'Card declined']))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'Card declined') && str_contains($m, 'not been charged'));

        $this->assertSame(['failed', 'Card declined'], [$checkout->fresh()->status, $checkout->fresh()->message]);
        $this->assertSame(0, Payment::count());
    }

    public function test_when_wipay_cannot_open_a_checkout_the_client_is_told_and_nothing_is_charged(): void
    {
        Http::fake(['tt.wipayfinancial.com/*' => Http::response(['message' => 'error'], 500)]);

        $this->actingAs($this->clientUser)->from(route('portal.invoices.show', $this->invoice))->post(route('portal.invoices.card', $this->invoice))
            ->assertSessionHasErrors(['card' => 'The card payment page could not be opened just now. Please try again in a few minutes, or pay by bank transfer.']);
        $this->assertSame('failed', CardCheckout::sole()->status);
    }

    public function test_card_payment_is_hidden_when_switched_off_or_nothing_is_owed(): void
    {
        config(['company.wipay.enabled' => false]);
        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $this->invoice))->assertDontSee('by card');
        $this->post(route('portal.invoices.card', $this->invoice))->assertSessionHasErrors('card');

        config(['company.wipay.enabled' => true]);
        Payment::create(['client_id' => $this->client->id, 'invoice_id' => $this->invoice->id, 'amount' => 1250, 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => now(), 'status' => 'pending']);
        $this->get(route('portal.invoices.show', $this->invoice))->assertDontSee('by card'); // a payment already covers it, awaiting verification
    }

    public function test_another_client_cannot_start_a_checkout_on_this_invoice(): void
    {
        $other = Client::create(['company' => 'Other', 'contact_name' => 'Bo', 'email' => 'bo@x.test']);
        $intruder = User::create(['name' => 'Bo', 'email' => 'bo@x.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $other->id]);
        $intruder->forceFill(['welcomed_at' => now()])->save();

        $this->actingAs($intruder)->post(route('portal.invoices.card', $this->invoice))->assertNotFound();
        $this->assertSame(0, CardCheckout::count());
    }
}
