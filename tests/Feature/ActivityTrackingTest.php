<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ActivityController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Who signed in, when, and each client login's last activity. */
class ActivityTrackingTest extends TestCase
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

    public function test_signing_in_is_recorded_with_time_ip_and_device(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1')
            ->post(route('login.attempt'), ['email' => 'ann@acme.test', 'password' => 'secret-password'])->assertRedirect();

        $event = DB::table('login_events')->sole();
        $this->assertSame($this->clientUser->id, $event->user_id);
        $this->assertSame('127.0.0.1', $event->ip);
        $this->assertSame('Safari on iPhone', ActivityController::device($event->user_agent));
        $user = $this->clientUser->fresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('Signed in', $user->last_activity);
    }

    public function test_the_latest_activity_is_described_in_plain_words(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent']);
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->clientUser)->get(route('portal.invoices.show', $invoice));
        $this->assertSame('Viewed invoice '.$invoice->reference, $this->clientUser->fresh()->last_activity);

        $this->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept']);
        $this->assertSame('Accepted quotation '.$quotation->reference, $this->clientUser->fresh()->last_activity);
        $this->assertNotNull($this->clientUser->fresh()->last_seen_at);
    }

    public function test_a_form_that_came_back_with_errors_is_not_recorded_as_done(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]]);
        $this->actingAs($this->clientUser)->get(route('portal.payments.create'));

        $this->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 999, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')])->assertSessionHasErrors('amount');

        $this->assertSame('Opened the payment form', $this->clientUser->fresh()->last_activity);
    }

    public function test_an_admin_preview_is_never_recorded_as_the_client(): void
    {
        $this->actingAs($this->admin)->post(route('admin.clients.preview', $this->client));
        $this->get(route('portal.invoices.index'));

        $this->assertNull($this->clientUser->fresh()->last_activity);
    }

    public function test_the_activity_page_and_client_page_show_it(): void
    {
        $this->clientUser->forceFill(['last_login_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(5), 'last_activity' => 'Viewed invoice INV-000070'])->save();
        $never = User::create(['name' => 'Bo', 'email' => 'bo@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        DB::table('login_events')->insert(['user_id' => $this->clientUser->id, 'ip' => '190.58.1.2', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', 'created_at' => now()->subHour()]);

        $this->actingAs($this->admin)->get(route('admin.activity'))->assertOk()
            ->assertSeeInOrder(['Ann', 'Acme', '1 hour ago', 'Viewed invoice INV-000070', 'Bo', 'Never'])
            ->assertSee('Chrome on Windows')->assertSee('190.58.1.2');
        $this->get(route('admin.activity', ['show' => 'never']))->assertSee('bo@acme.test')->assertDontSee('ann@acme.test');
        $this->get(route('admin.clients.show', $this->client))->assertSee('Viewed invoice INV-000070, 5 minutes ago')->assertSee('Has not signed in yet');
        $this->assertNull($never->last_login_at);
    }
}
