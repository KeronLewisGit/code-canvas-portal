<?php

namespace Tests\Feature;

use App\Jobs\IssueRecurringInvoices;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ContractInvoicing;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The Success Laventille proposal: a draft contract in four-month terms from 1 June 2026, and three earlier cash-paid invoices with receipts, two of them the first two terms. */
class SlssProposalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Success Laventille Secondary School', 'contact_name' => 'The Principal', 'email' => 'office@slss.test']);
        $this->clientUser = User::create(['name' => 'Principal', 'email' => 'office@slss.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->clientUser->forceFill(['welcomed_at' => now(), 'password_changed_at' => now()])->save();
    }

    public function test_the_contract_is_drafted_with_the_proposal_content_from_the_first_of_june(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $contract = Contract::sole();
        $this->assertSame(0, Quotation::count());
        $this->assertSame(['draft', 'four_monthly', false, 'TTD', 30, 14], [$contract->status, $contract->billing_cycle, $contract->bill_in_arrears, $contract->currency, $contract->notice_period_days, $contract->payment_terms_days]);
        $this->assertEquals(2600, (float) $contract->amount);
        $this->assertEquals(0, (float) $contract->deposit_percent);
        $this->assertSame('2026-06-01', $contract->start_date->toDateString());
        $this->assertNull($contract->end_date);
        $this->assertStringContainsString('Registration management', $contract->scope);
        $this->assertStringContainsString('Security issue: within 12 business hours', $contract->scope);
        $this->assertStringContainsString('took effect on 1 June 2026', $contract->terms);
        $this->assertStringContainsString('invoiced at the start of that four-month term', $contract->terms);
        $this->assertStringContainsString('next maintenance invoice is issued on 1 February 2027', $contract->terms);
        $this->assertStringContainsString('slss.edu.tt only', $contract->terms);
        $this->assertNull($contract->sent_for_signature_at);
        $this->actingAs($this->clientUser)->get(route('portal.contracts.show', $contract))->assertNotFound();
    }

    public function test_the_three_cash_invoices_are_recorded_as_paid_with_receipts_and_nobody_is_emailed(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $invoices = Invoice::orderBy('reference')->get();
        $this->assertSame(['INV-000049', 'INV-000051', 'INV-000063'], $invoices->pluck('reference')->all());
        $this->assertSame([5000.0, 750.0, 2600.0], $invoices->map(fn ($i) => (float) $i->total)->all());
        $this->assertSame(['paid', 'paid', 'paid'], $invoices->pluck('status')->all());
        $this->assertSame(['2026-06-01', '2026-06-12', '2026-10-09'], $invoices->map(fn ($i) => $i->paid_at->toDateString())->all());
        $this->assertSame('2026-07-27', $invoices[1]->due_date->toDateString());
        $this->assertSame('Net 45', $invoices[1]->termsLabel());

        $payments = Payment::orderBy('paid_on')->get();
        $this->assertSame(['cash', 'cash', 'cash'], $payments->pluck('method')->all());
        $this->assertSame(['verified', 'verified', 'verified'], $payments->pluck('status')->all());
        $this->assertSame(['RCT-2026-001', 'RCT-2026-002', 'RCT-2026-003'], $payments->pluck('receipt_number')->all());
        $this->assertSame([5000.0, 750.0, 2600.0], $payments->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame(8350.0, (float) $payments->sum('amount'));
        Notification::assertNothingSent();
    }

    public function test_the_client_sees_the_invoices_and_receipts_in_the_portal(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $this->actingAs($this->clientUser)->get(route('portal.invoices.index'))->assertOk()->assertSee('INV-000049')->assertSee('INV-000051')->assertSee('INV-000063');
        $this->actingAs($this->clientUser)->get(route('portal.receipts.index'))->assertOk()->assertSee('RCT-2026-001')->assertSee('RCT-2026-003');
        $this->actingAs($this->clientUser)->get(route('documents.receipt', Payment::orderBy('paid_on')->first()))->assertOk();
    }

    public function test_rerunning_after_the_draft_is_deleted_attaches_the_existing_invoices_to_the_new_contract(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();
        // As if the invoices had been imported before the proposal named their terms: unlinked, like an older import.
        Invoice::query()->update(['contract_id' => null, 'kind' => 'standard', 'period_start' => null]);
        Payment::query()->update(['contract_id' => null]);
        Contract::sole()->delete();

        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful()
            ->expectsOutputToContain('INV-000049 was already on this profile and is now attached to the contract as the term from 1 Jun 2026')
            ->expectsOutputToContain('INV-000051 was already on this profile and is now attached to the contract.');

        $contract = Contract::sole();
        $this->assertSame([3, 3], [Invoice::count(), Payment::count()], 'nothing is duplicated');
        $this->assertSame(3, $contract->invoices()->count());
        $terms = $contract->invoices()->where('kind', 'cycle')->reorder()->orderBy('period_start')->get();
        $this->assertSame(['INV-000049', 'INV-000063'], $terms->pluck('reference')->all());
        $this->assertSame(['2026-06-01', '2026-10-01'], $terms->map(fn ($i) => $i->period_start->toDateString())->all());
        $this->assertSame(3, $contract->payments()->where('status', 'verified')->count());
        $this->assertSame(8350.0, $contract->paidAmount(), 'TT$5,000 on INV-000049 (the hosting renewal was on the same invoice), TT$750 restoration, TT$2,600 on INV-000063');
        $this->assertSame(0.0, $contract->balance());
        $this->assertSame('paid', $contract->paymentState());
    }

    public function test_after_the_draft_is_withdrawn_and_archived_it_can_be_drafted_again_and_takes_the_invoices_with_it(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();
        $old = Contract::sole();
        $this->actingAs(User::where('role', 'admin')->first())->patch(route('admin.contracts.archive', $old))->assertRedirect();
        $this->assertSame(['cancelled', true, 3], [$old->fresh()->status, $old->fresh()->isArchived(), $old->invoices()->count()]);

        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $new = Contract::where('status', 'draft')->sole();
        $this->assertSame([3, 3, 2], [Invoice::count(), Payment::count(), Contract::count()], 'nothing is duplicated');
        $this->assertSame(0, $old->invoices()->count());
        $this->assertSame(['INV-000049', 'INV-000051', 'INV-000063'], $new->invoices()->reorder()->orderBy('reference')->pluck('reference')->all());
        $this->assertSame(['2026-06-01', '2026-10-01'], $new->invoices()->where('kind', 'cycle')->reorder()->orderBy('period_start')->get()->map(fn ($i) => $i->period_start->toDateString())->all());
        $this->assertSame(3, $new->payments()->where('status', 'verified')->count());
        $this->assertSame([0.0, 'paid', '2027-02-01'], [$new->balance(), $new->paymentState(), $new->nextDueDate()->toDateString()]);
        $this->assertSame(['paid', 'paid', 'paid'], Invoice::orderBy('reference')->pluck('status')->all(), 'paid invoices are not voided by the withdrawal');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertFailed();

        $this->assertSame([1, 3, 3], [Contract::count(), Invoice::count(), Payment::count()]);
    }

    public function test_an_invoice_number_used_by_another_client_stops_everything(): void
    {
        $other = Client::create(['company' => 'Other Ltd', 'contact_name' => 'Sam', 'email' => 'sam@other.test']);
        Invoice::create(['reference' => 'INV-000063', 'client_id' => $other->id, 'title' => 'Other work', 'issue_date' => now(), 'status' => 'sent', 'currency' => 'TTD']);

        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertFailed();

        $this->assertSame([0, 1, 0], [Contract::count(), Invoice::count(), Payment::count()]);
    }

    public function test_the_october_term_starts_four_months_after_the_june_start(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $contract = Contract::sole();
        $this->assertSame(['2026-06-01', '2026-09-30'], [$contract->cycleStart(0)->toDateString(), $contract->periodEnd(0)->toDateString()]);
        $this->assertSame(['2026-10-01', '2027-01-31'], [$contract->cycleStart(1)->toDateString(), $contract->periodEnd(1)->toDateString()]);
        $this->assertSame(['2027-02-01', '2027-05-31'], [$contract->cycleStart(2)->toDateString(), $contract->periodEnd(2)->toDateString()]);
        $this->assertSame(2, $contract->cyclesElapsed(), 'today (October 2026) is in the second term');
        $this->assertSame('2027-02-01', $contract->nextDueDate()->toDateString());
    }

    public function test_the_june_and_october_invoices_are_the_first_two_terms_and_nothing_is_owed(): void
    {
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();

        $contract = Contract::sole();
        $this->assertSame(3, $contract->invoices()->count(), 'all three earlier invoices are on the contract');
        $terms = $contract->invoices()->where('kind', 'cycle')->reorder()->orderBy('period_start')->get();
        $this->assertSame(['INV-000049', 'INV-000063'], $terms->pluck('reference')->all());
        $this->assertSame(['2026-06-01', '2026-10-01'], $terms->map(fn ($i) => $i->period_start->toDateString())->all());
        $restoration = Invoice::where('reference', 'INV-000051')->sole();
        $this->assertSame([$contract->id, 'standard', null], [$restoration->contract_id, $restoration->kind, $restoration->period_start], 'the restoration invoice is on the contract as a one-off, not a term');
        $this->assertSame(3, $contract->payments()->count());
        $this->assertSame(8350.0, $contract->paidAmount());

        $this->assertSame(5200.0, $contract->dueToDate(), 'two terms have started');
        $this->assertSame(0.0, $contract->balance());
        $this->assertSame('paid', $contract->paymentState());
        $this->assertFalse($contract->acceptsPayments());
        $this->assertSame(0.0, (float) Invoice::where('status', '!=', 'paid')->sum('total'));
    }

    public function test_sent_and_signed_it_invoices_nothing_until_the_third_term_starts_in_february(): void
    {
        Storage::fake('local');
        $this->artisan('portal:draft-proposal', ['proposal' => 'slss-maintenance'])->assertSuccessful();
        $contract = Contract::sole();
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $this->actingAs(User::where('role', 'admin')->first())->patch(route('admin.contracts.signature', $contract), ['action' => 'request'])->assertRedirect();
        $this->actingAs($this->clientUser)->get(route('portal.contracts.sign', $contract))->assertOk()->assertSee('Special terms')->assertSee('TT$2,600.00 per four-month term');
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'The Principal', 'agree' => 1, 'signature' => $png])
            ->assertRedirect(route('portal.contracts.show', $contract));

        $contract->refresh();
        $this->assertSame('active', $contract->status);
        $this->assertSame(3, $contract->invoices()->count(), 'the three earlier invoices are the only ones: nothing new is invoiced when the contract is signed');
        $this->assertSame('2027-02-01', $contract->nextDueDate()->toDateString());

        Carbon::setTestNow('2027-01-31 08:00:00');
        $this->assertSame(0, $this->runDailyBilling($contract));
        Carbon::setTestNow('2027-02-01 08:00:00');
        $this->assertSame(1, $this->runDailyBilling($contract));
        $this->assertSame(0, $this->runDailyBilling($contract), 'a rerun the same day bills nothing more');
        $third = $contract->invoices()->where('kind', 'cycle')->reorder()->orderByDesc('period_start')->first();
        $this->assertSame('2027-02-01', $third->period_start->toDateString());
        $this->assertStringContainsString('1 Feb 2027 – 31 May 2027', $third->title);
        $this->assertEquals(2600, (float) $third->total);
        Carbon::setTestNow();
    }

    private function runDailyBilling(Contract $contract): int
    {
        $before = $contract->invoices()->count();
        (new IssueRecurringInvoices)->handle(app(ContractInvoicing::class), app(Notifier::class));

        return $contract->invoices()->count() - $before;
    }
}
