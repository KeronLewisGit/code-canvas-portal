<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Package 5 remainder: preview as client, sortable lists, due-now column. */
class AdminToolsTest extends TestCase
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
    }

    public function test_an_admin_can_preview_the_portal_as_the_client_read_only_and_come_back(): void
    {
        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'sent', 'sent_at' => now()]);
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'App', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'active']);

        $this->actingAs($this->admin)->post(route('admin.clients.preview', $this->client))->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($this->clientUser);
        $this->get(route('portal.dashboard'))->assertOk()->assertSee('Preview:')->assertSee('Back to admin');

        // Nothing can be changed, and the client's audit trail is untouched.
        $this->from(route('portal.quotations.show', $quotation))->patch(route('portal.quotations.answer', $quotation), ['answer' => 'accept'])->assertSessionHas('warning', fn ($w) => str_contains($w, 'read-only preview'));
        $this->assertSame('sent', $quotation->fresh()->status);
        $this->get(route('portal.contracts.show', $contract))->assertOk();
        $this->assertFalse($contract->activity()->where('action', 'viewed_by_client')->exists());
        $this->assertNull($this->clientUser->fresh()->welcomed_at, 'the welcome screen is not marked as seen');

        $this->post(route('preview.stop'))->assertRedirect(route('admin.clients.show', $this->client));
        $this->assertAuthenticatedAs($this->admin);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_preview_needs_an_active_client_with_a_login(): void
    {
        $this->client->update(['active' => false]);

        $this->actingAs($this->admin)->post(route('admin.clients.preview', $this->client))->assertSessionHasErrors('preview');
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_lists_sort_by_a_chosen_column_and_ignore_unknown_ones(): void
    {
        foreach ([300, 100, 200] as $n => $amount) {
            $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Inv '.$amount, 'issue_date' => now()->subDays($n), 'status' => 'sent']);
            $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount]]);
        }

        $this->actingAs($this->admin)->get(route('admin.invoices.index', ['sort' => 'total', 'dir' => 'asc']))->assertSeeInOrder(['Inv 100', 'Inv 200', 'Inv 300'])->assertSee('aria-sort="ascending"', false);
        $this->get(route('admin.invoices.index', ['sort' => 'total', 'dir' => 'desc']))->assertSeeInOrder(['Inv 300', 'Inv 200', 'Inv 100']);
        $this->get(route('admin.invoices.index', ['sort' => 'password', 'dir' => 'asc']))->assertOk();
    }

    public function test_the_client_list_shows_what_each_client_owes_now(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 450]]);

        $this->actingAs($this->admin)->get(route('admin.clients.index'))->assertSee('Due now')->assertSee('TT$450.00');
    }
}
