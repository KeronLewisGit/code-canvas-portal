<?php

namespace Tests\Feature;

use App\Jobs\SendSignatureReminders;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Contracts that are not signed: automatic reminders, an overdue alert, and withdraw-and-archive. */
class UnsignedContractsTest extends TestCase
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

    private function sentContract(array $extra = []): Contract
    {
        return Contract::create($extra + ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'approved', 'sent_for_signature_at' => now()]);
    }

    private function runOn(string $date): void
    {
        Carbon::setTestNow($date);
        (new SendSignatureReminders)->handle(app(Notifier::class));
    }

    private function reminders(): int
    {
        return Notification::sent($this->clientUser, PortalMail::class)->filter(fn (PortalMail $m) => str_starts_with($m->subject, 'Reminder: contract'))->count();
    }

    public function test_reminders_go_out_after_3_days_then_weekly_and_stop_at_30_days_with_one_admin_alert(): void
    {
        Carbon::setTestNow('2027-03-01 09:30');
        $contract = $this->sentContract();

        $this->runOn('2027-03-02 09:30');
        $this->assertSame(0, $this->reminders(), 'too soon');
        $this->runOn('2027-03-04 09:30');
        $this->runOn('2027-03-05 09:30');
        $this->assertSame(1, $this->reminders(), 'day 3, and not again the next day');
        $this->runOn('2027-03-11 09:30');
        $this->assertSame(2, $this->reminders(), 'a week later');

        $this->runOn('2027-03-31 09:30');
        $this->runOn('2027-04-01 09:30');
        Notification::assertSentToTimes($this->admin, PortalMail::class, 1);
        Notification::assertSentTo($this->admin, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'waited 30+ days') && str_contains(implode(' ', $m->lines), $contract->reference));
        $remindersAtOverdue = $this->reminders();
        $this->runOn('2027-04-15 09:30');
        $this->assertSame($remindersAtOverdue, $this->reminders(), 'no more client reminders once overdue');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Unsigned 30+ days');
        $this->get(route('admin.contracts.show', $contract))->assertSee('days for a signature')->assertSee('Withdraw and archive');
        Carbon::setTestNow();
    }

    public function test_withdrawing_and_archiving_an_unsigned_contract_cancels_it_and_hides_it_from_the_client(): void
    {
        $contract = $this->sentContract();
        $invoice = Invoice::create(['client_id' => $this->client->id, 'contract_id' => $contract->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent']);

        $this->actingAs($this->admin)->patch(route('admin.contracts.archive', $contract))->assertRedirect(route('admin.contracts.index'))->assertSessionHas('saved', fn ($m) => str_contains($m, 'withdrawn and archived'));

        $contract->refresh();
        $this->assertSame('cancelled', $contract->status);
        $this->assertTrue($contract->isArchived());
        $this->assertSame('cancelled', $invoice->fresh()->status);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'was cancelled'));
        $this->get(route('admin.contracts.index')); // shows the confirmation (which names it) once
        $this->get(route('admin.contracts.index'))->assertDontSee($contract->reference);
        $this->get(route('admin.contracts.index', ['view' => 'archived']))->assertSee($contract->reference);
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertNotFound();

        $this->actingAs($this->admin)->patch(route('admin.contracts.unarchive', $contract));
        $this->assertFalse($contract->fresh()->isArchived());
    }

    public function test_signed_contracts_stay_on_the_clients_record_when_archived_and_active_ones_cannot_be(): void
    {
        $active = $this->sentContract(['status' => 'active', 'signed_at' => now()]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.archive', $active))->assertSessionHasErrors('action');

        $completed = $this->sentContract(['status' => 'completed', 'signed_at' => now()]);
        $this->patch(route('admin.contracts.archive', $completed))->assertSessionHas('saved', fn ($m) => str_contains($m, 'was archived'));
        $this->assertSame('completed', $completed->fresh()->status);
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $completed))->assertOk();
    }

    public function test_archived_contracts_get_no_reminders(): void
    {
        Carbon::setTestNow('2027-03-01 09:30');
        $this->sentContract(['archived_at' => now()]);

        $this->runOn('2027-03-10 09:30');

        $this->assertSame(0, $this->reminders());
        Carbon::setTestNow();
    }
}
