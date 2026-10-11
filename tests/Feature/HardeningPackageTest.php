<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ReportController;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Package 1: urgent hardening. */
class HardeningPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now(), 'remember_token' => 'old-token'])->save();
    }

    private function invoice(float $amount = 500): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice;
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_a_deactivated_client_loses_documents_files_and_account_too(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->clientUser)->get(route('documents.invoice', $invoice))->assertOk();

        $this->actingAs($this->admin)->patch(route('admin.clients.update', $this->client), ['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test'])
            ->assertSessionHas('saved', fn ($m) => str_contains($m, 'signed out'));
        $this->assertNotSame('old-token', $this->clientUser->fresh()->remember_token, 'remember-me cookies stop working');

        $this->actingAs($this->clientUser->fresh())->get(route('documents.invoice', $invoice))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($this->clientUser->fresh())->get(route('account'))->assertRedirect(route('login'));
    }

    public function test_an_admin_password_reset_signs_the_client_out_everywhere(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.clients.users.update', [$this->client, $this->clientUser]));

        $this->assertNotSame('old-token', $this->clientUser->fresh()->remember_token);
    }

    public function test_spreadsheet_formulas_typed_by_clients_are_neutralised_in_csv_exports(): void
    {
        $safe = (new \ReflectionMethod(ReportController::class, 'safeCell'))->getClosure(null);

        $this->assertSame("'=HYPERLINK(\"https://evil\",\"x\")", $safe('=HYPERLINK("https://evil","x")'));
        $this->assertSame("'+1+1", $safe('+1+1'));
        $this->assertSame("'@SUM(A1)", $safe('@SUM(A1)'));
        $this->assertSame('TT-123 transfer', $safe('TT-123 transfer'));
        $this->assertSame(12.5, $safe(12.5));
    }

    public function test_clients_can_have_at_most_five_payments_waiting(): void
    {
        $invoice = $this->invoice(10000);
        $pay = fn () => $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 10, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d')]);

        foreach (range(1, 5) as $i) {
            $pay()->assertSessionHasNoErrors();
        }
        $pay()->assertSessionHasErrors('amount');
        $this->assertSame(5, Payment::count());
    }

    public function test_sign_in_attempts_are_limited_per_email_address(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('login.attempt'), ['email' => 'admin@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post(route('login.attempt'), ['email' => 'admin@example.com', 'password' => 'secret-password'])->assertStatus(429);
    }

    public function test_uploaded_proofs_open_sandboxed(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'bank_transfer', 'paid_on' => now()->format('Y-m-d'), 'proof' => UploadedFile::fake()->image('slip.png')]);

        $this->actingAs($this->admin)->get(route('files.proof', Payment::sole()))->assertOk()->assertHeader('Content-Security-Policy', 'sandbox');
    }

    public function test_client_typed_text_cannot_become_a_link_in_an_email(): void
    {
        $html = (string) (new PortalMail('Signed', ['[Verify this signature](https://phish.example) signed the contract.']))->toMail($this->admin)->render();

        $this->assertStringNotContainsString('href="https://phish.example"', $html);
        $this->assertStringContainsString('[Verify this signature](https://phish.example)', $html);
    }

    public function test_contracts_with_nothing_due_read_nothing_due_yet_not_not_due(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Hosting', 'type' => 'hosting', 'start_date' => now()->addMonth(), 'amount' => 100, 'billing_cycle' => 'yearly', 'status' => 'active']);

        $this->actingAs($this->admin)->get(route('admin.contracts.show', $contract))->assertSee('Nothing due yet')->assertDontSee('Not_due');
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertSee('Nothing due yet')->assertDontSee('Not_due');
    }

    public function test_accepting_a_quotation_asks_first(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->clientUser)->get(route('portal.quotations.show', $quotation))->assertSee('data-confirm="Accept this quotation?', false);
    }

    public function test_the_demo_seeder_refuses_to_run_on_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        app(DatabaseSeeder::class)->run();
    }
}
