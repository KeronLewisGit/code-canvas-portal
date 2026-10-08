<?php

namespace Tests\Feature;

use App\Jobs\ExpireContracts;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
    }

    private function contract(array $attrs = []): Contract
    {
        return Contract::create($attrs + ['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft']);
    }

    public function test_happy_path_draft_approved_active_completed_is_audited(): void
    {
        $c = $this->contract();
        $this->actingAs($this->admin);
        $lc = app(ContractLifecycle::class);
        $lc->transition($c, 'approve', $this->admin);
        $this->assertSame('approved', $c->status);
        $this->assertNotNull($c->approved_at);
        $lc->transition($c, 'activate', $this->admin);
        $lc->transition($c, 'complete', $this->admin);
        $this->assertSame('completed', $c->fresh()->status);
        $this->assertSame(['approve', 'activate', 'complete'], ActivityLog::orderBy('id')->pluck('action')->all());
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $c = $this->contract();
        $this->expectException(InvalidArgumentException::class);
        app(ContractLifecycle::class)->transition($c, 'complete');
    }

    public function test_termination_needs_a_reason_and_ends_billing(): void
    {
        $c = $this->contract(['status' => 'active', 'billing_cycle' => 'monthly', 'amount' => 500, 'start_date' => now()->subMonths(3)]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $c), ['action' => 'terminate'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->patch(route('admin.contracts.lifecycle', $c), ['action' => 'terminate', 'reason' => 'Client closed the business'])->assertRedirect();
        $c->refresh();
        $this->assertSame('terminated', $c->status);
        $this->assertSame('Client closed the business', $c->termination_reason);
        $this->assertEquals(0, $c->finance()->summary()['forecast_12m'], 'terminated contracts contribute nothing to the forecast');
        $this->assertEquals(0, $c->monthlyEquivalent() > 0 ? $c->finance()->monthlyRecurring() : 0);
    }

    public function test_amending_an_active_contract_keeps_an_immutable_version(): void
    {
        $c = $this->contract(['status' => 'active', 'scope' => 'Five pages']);
        $this->actingAs($this->admin)->patch(route('admin.contracts.update', $c), [
            'client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now()->format('Y-m-d'), 'amount' => 1500, 'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 14, 'scope' => 'Five pages plus blog', 'amendment_reason' => 'Added blog',
        ])->assertRedirect();
        $c->refresh();
        $this->assertSame(2, $c->version);
        $this->assertEquals(1500, $c->amount);
        $v1 = $c->versions()->first();
        $this->assertSame(1, $v1->version);
        $this->assertEquals(1000, $v1->snapshot['amount']);
        $this->assertSame('Five pages', $v1->snapshot['scope']);
        $this->assertSame('Added blog', $v1->reason);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $c->id, 'action' => 'amended']);
        $this->actingAs($this->admin)->get(route('admin.contracts.version', [$c, 1]))->assertOk()->assertSee('Five pages plus blog');
    }

    public function test_editing_without_material_changes_does_not_bump_the_version(): void
    {
        $c = $this->contract(['status' => 'active']);
        app(ContractLifecycle::class)->amend($c, ['title' => 'Site'], $this->admin);
        $this->assertSame(1, $c->fresh()->version);
        $this->assertSame(0, $c->versions()->count());
    }

    public function test_renewal_drafts_a_linked_follow_on_contract(): void
    {
        $c = $this->contract(['status' => 'active', 'start_date' => now()->subYear(), 'end_date' => now()->addDays(10), 'billing_cycle' => 'monthly', 'amount' => 300]);
        $this->actingAs($this->admin)->post(route('admin.contracts.renew', $c))->assertRedirect();
        $renewal = Contract::where('renewal_of_id', $c->id)->first();
        $this->assertNotNull($renewal);
        $this->assertSame('draft', $renewal->status);
        $this->assertTrue($renewal->start_date->isSameDay($c->end_date->copy()->addDay()));
        $this->assertEquals(300, $renewal->amount);
        $this->assertSame('monthly', $renewal->billing_cycle);
    }

    public function test_scheduler_expires_past_contracts_and_auto_renews_when_asked(): void
    {
        $plain = $this->contract(['status' => 'active', 'start_date' => now()->subMonths(2), 'end_date' => now()->subDay()]);
        $auto = $this->contract(['status' => 'active', 'start_date' => now()->subYear(), 'end_date' => now()->subDay(), 'auto_renew' => true, 'billing_cycle' => 'monthly', 'amount' => 100]);
        $this->assertSame('expired', $plain->effectiveStatus(), 'reads as expired before the job runs');

        (new ExpireContracts)->handle(app(ContractLifecycle::class));

        $this->assertSame('expired', $plain->fresh()->status);
        $this->assertSame('expired', $auto->fresh()->status);
        $renewal = Contract::where('renewal_of_id', $auto->id)->first();
        $this->assertSame('active', $renewal->status);
        $this->assertTrue($renewal->end_date->gt(now()));
    }

    public function test_sending_for_signature_approves_and_client_signature_activates(): void
    {
        $c = $this->contract();
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $c), ['action' => 'request'])->assertRedirect();
        $this->assertSame('approved', $c->fresh()->status);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $this->actingAs($user)->post(route('portal.contracts.sign.store', $c), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $png])->assertRedirect();
        $this->assertSame('active', $c->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $c->id, 'action' => 'signed_by_client']);
    }

    public function test_signed_or_invoiced_contracts_cannot_be_deleted(): void
    {
        $c = $this->contract(['status' => 'active', 'signed_at' => now()]);
        $this->actingAs($this->admin)->delete(route('admin.contracts.destroy', $c))->assertForbidden();
        $this->assertDatabaseHas('contracts', ['id' => $c->id]);
    }
}
