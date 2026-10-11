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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The whole client journey, end to end: onboarding, quotation, contract, deposit invoice, payment. */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_client_journey(): void
    {
        Notification::fake();
        Storage::fake('local');
        Setting::forget();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);

        // 1. The admin adds the client; the login is created and the welcome emailed.
        $this->actingAs($admin)->post(route('admin.clients.store'), ['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test', 'active' => '1', 'create_login' => '1']);
        $client = Client::sole();
        $user = $client->users()->sole();
        $url = Notification::sent($user, PortalMail::class)->last()->actionUrl;
        auth()->logout();

        // 2. The client sets a password from the email link, signs in, and sees the welcome; nothing to pay yet.
        $this->post(route('password.invite.update'), ['token' => basename((string) parse_url($url, PHP_URL_PATH)), 'email' => 'ann@acme.test', 'password' => 'ann-own-password', 'password_confirmation' => 'ann-own-password'])->assertRedirect(route('login'));
        $this->post(route('login.attempt'), ['email' => 'ann@acme.test', 'password' => 'ann-own-password'])->assertRedirect(route('portal.welcome'));
        $this->get(route('portal.welcome'))->assertDontSee('Choose your own password');
        $this->post(route('portal.welcome.complete'));
        $this->get(route('portal.dashboard'))->assertSee('Your portal is ready')->assertDontSee('Submit a payment');
        $this->get(route('portal.payments.create'))->assertRedirect(route('portal.dashboard'));

        // 3. The admin sends a quotation; the client is emailed to review it.
        $this->actingAs($admin)->post(route('admin.quotations.store'), [
            'client_id' => $client->id, 'title' => 'Booking site', 'issue_date' => now()->format('Y-m-d'), 'currency' => 'TTD',
            'items' => [['description' => 'Build', 'quantity' => 1, 'unit_price' => 8000]],
            'contract_type' => 'booking-system', 'billing_cycle' => 'one_time', 'auto_send_contract' => '1', 'send' => '1',
        ]);
        $quotation = Quotation::sole();
        Notification::assertSentTo($user, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'is ready for you'));

        // 4. The client accepts; the contract is generated and they are taken to sign it.
        $this->actingAs($user->fresh())->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept']);
        $contract = Contract::sole();
        $this->assertTrue($contract->awaitingSignature());
        $this->get(route('portal.dashboard'))->assertSee('Read and sign');

        // 5. The client signs; the 50% deposit invoice is issued and they can pay it.
        $this->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==']);
        $invoice = Invoice::sole();
        $this->assertSame(['active', 'deposit'], [$contract->fresh()->status, $invoice->kind]);
        $this->assertEquals(4000, (float) $invoice->total);
        $this->get(route('portal.dashboard'))->assertSee('Submit a payment');

        // 6. The client submits the deposit payment for verification.
        $this->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 4000, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertRedirect();
        $this->assertSame($contract->id, Payment::sole()->contract_id);
    }
}
