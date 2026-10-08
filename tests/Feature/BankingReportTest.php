<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Services\BankingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
    }

    public function test_report_includes_only_consented_non_confidential_signed_contracts(): void
    {
        $consented = Client::create(['company' => 'Yes Ltd', 'contact_name' => 'Y', 'email' => 'y@y.test', 'disclosure_consent_at' => now()]);
        $silent = Client::create(['company' => 'No Ltd', 'contact_name' => 'N', 'email' => 'n@n.test']);
        $a = Contract::create(['client_id' => $consented->id, 'title' => 'Public', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now()]);
        Contract::create(['client_id' => $consented->id, 'title' => 'Secret', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now(), 'confidential' => true]);
        Contract::create(['client_id' => $silent->id, 'title' => 'Unconsented', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now()]);
        Contract::create(['client_id' => $consented->id, 'title' => 'Draft', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft']);

        $this->actingAs($this->admin);
        $report = app(BankingReport::class)->build();
        $this->assertSame(1, $report['meta']['contracts_included']);
        $this->assertSame(2, $report['meta']['contracts_excluded']);
        $this->assertSame([$a->reference], $report['register']->pluck('reference')->all());
        $this->assertFalse($report['meta']['certified']);
        $this->assertSame(64, strlen($report['meta']['verification_hash']));

        $preview = app(BankingReport::class)->build(includeUnconsented: true);
        $this->assertSame(2, $preview['meta']['contracts_included'], 'internal preview adds unconsented clients but never confidential contracts');
    }

    public function test_report_page_and_csv_export_are_admin_only_and_audited(): void
    {
        $client = Client::create(['company' => 'C', 'contact_name' => 'C', 'email' => 'c@c.test', 'disclosure_consent_at' => now()]);
        $user = User::create(['name' => 'C', 'email' => 'c@c.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        Contract::create(['client_id' => $client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active', 'signed_at' => now(), 'billing_cycle' => 'monthly']);

        $this->actingAs($user)->get(route('admin.reports.banking'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.reports.banking'))->assertOk()->assertSee('not audited or certified')->assertSee('Signed contract register');
        $csv = $this->actingAs($this->admin)->get(route('admin.reports.banking.export', ['part' => 'forecast']))->assertOk()->streamedContent();
        $this->assertStringContainsString('month,forecast,cancellable,committed', $csv);
        $this->assertSame(14, substr_count($csv, "\n"), 'header comment, column row and 12 month rows');
        $this->assertDatabaseHas('activity_logs', ['action' => 'banking_report_viewed']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'banking_report_exported']);
    }

    public function test_dashboard_renders_with_filters_and_alerts(): void
    {
        $client = Client::create(['company' => 'C', 'contact_name' => 'C', 'email' => 'c@c.test']);
        Contract::create(['client_id' => $client->id, 'title' => 'Ending', 'type' => 'hosting', 'start_date' => now()->subYear(), 'end_date' => now()->addDays(20), 'amount' => 100, 'billing_cycle' => 'monthly', 'status' => 'active']);
        $this->actingAs($this->admin)->get(route('admin.reports.revenue'))->assertOk()->assertSee('Renewals due')->assertSee('Ending')->assertSee('Forecast billings');
        $this->actingAs($this->admin)->get(route('admin.reports.revenue', ['type' => 'website']))->assertOk()->assertSee('No contracts match these filters');
    }
}
