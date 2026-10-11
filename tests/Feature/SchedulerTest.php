<?php

namespace Tests\Feature;

use App\Jobs\SendRenewalReminders;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Scheduled jobs: wiring, the renewal digest, and cache pruning. */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_job_is_scheduled_once_and_never_overlaps(): void
    {
        $events = collect(app(Schedule::class)->events());
        $byName = $events->mapWithKeys(fn ($e) => [$e->description => $e]);

        foreach (['App\\Jobs\\ExpireContracts' => '0 1 * * *', 'App\\Jobs\\IssueRecurringInvoices' => '0 6 * * *', 'App\\Jobs\\SendRenewalReminders' => '0 8 * * 1', 'App\\Jobs\\SendPaymentReminders' => '0 9 * * 2', 'prune-expired-cache' => '0 3 * * *'] as $name => $cron) {
            $this->assertArrayHasKey($name, $byName->all(), "$name is scheduled");
            $this->assertSame($cron, $byName[$name]->expression);
            $this->assertTrue($byName[$name]->withoutOverlapping, "$name never runs on top of itself");
        }
    }

    public function test_the_renewal_digest_lists_contracts_ending_without_a_renewal(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $ending = Contract::create(['client_id' => $client->id, 'title' => 'Hosting', 'type' => 'hosting', 'start_date' => now()->subYear(), 'end_date' => now()->addDays(20), 'amount' => 100, 'status' => 'active']);
        Contract::create(['client_id' => $client->id, 'title' => 'Auto', 'type' => 'hosting', 'start_date' => now()->subYear(), 'end_date' => now()->addDays(20), 'amount' => 100, 'status' => 'active', 'auto_renew' => true]);
        Contract::create(['client_id' => $client->id, 'title' => 'Far off', 'type' => 'hosting', 'start_date' => now(), 'end_date' => now()->addYear(), 'amount' => 100, 'status' => 'active']);

        (new SendRenewalReminders)->handle();

        Notification::assertSentTo($admin, PortalMail::class, fn (PortalMail $m) => $m->subject === '1 contract ending soon' && str_contains(implode(' ', $m->lines), $ending->reference) && ! str_contains(implode(' ', $m->lines), 'Auto'));
    }

    public function test_expired_cache_rows_are_pruned(): void
    {
        DB::table('cache')->insert([['key' => 'old', 'value' => 'x', 'expiration' => now()->subDay()->getTimestamp()], ['key' => 'fresh', 'value' => 'y', 'expiration' => now()->addDay()->getTimestamp()]]);

        collect(app(Schedule::class)->events())->firstWhere('description', 'prune-expired-cache')->run($this->app);

        $this->assertSame(['fresh'], DB::table('cache')->pluck('key')->all());
    }
}
