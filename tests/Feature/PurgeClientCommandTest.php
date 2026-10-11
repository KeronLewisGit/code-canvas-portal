<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CardCheckout;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeClientCommandTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithHistory(string $company, string $email): Client
    {
        $client = Client::create(['company' => $company, 'contact_name' => 'Test', 'email' => $email]);
        $user = User::create(['name' => $company, 'email' => $email, 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        Storage::disk('local')->put("signatures/{$client->id}.png", 'png');
        Storage::disk('local')->put("payments/{$client->id}.png", 'png');
        $contract = Contract::create(['client_id' => $client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 100, 'status' => 'active', 'signature_path' => "signatures/{$client->id}.png", 'signed_at' => now()]);
        $quotation = Quotation::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'accepted']);
        $quotation->syncItems([['description' => 'Build', 'quantity' => 1, 'unit_price' => 100]]);
        $invoice = Invoice::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'title' => 'Deposit', 'issue_date' => now(), 'status' => 'sent']);
        $invoice->syncItems([['description' => 'Deposit', 'quantity' => 1, 'unit_price' => 50]]);
        $payment = Payment::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'amount' => 50, 'currency' => 'TTD', 'method' => 'bank_transfer', 'paid_on' => now(), 'status' => 'verified', 'proof_path' => "payments/{$client->id}.png", 'submitted_by' => $user->id]);
        CardCheckout::create(['order_id' => 'ORD-'.$client->id, 'client_id' => $client->id, 'invoice_id' => $invoice->id, 'user_id' => $user->id, 'amount' => 50, 'currency' => 'TTD']);
        Audit::log($contract, 'created');
        Audit::log($payment, 'verified');
        DB::table('invite_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);

        return $client;
    }

    public function test_a_dry_run_changes_nothing_and_a_confirmed_run_removes_everything_for_that_client_only(): void
    {
        Notification::fake();
        Storage::fake('local');
        $doomed = $this->clientWithHistory('Keron Lewis test', 'keronlewis@live.com');
        $kept = $this->clientWithHistory('Real Client Ltd', 'real@client.test');

        $this->artisan('portal:purge-client', ['client' => 'keronlewis@live.com'])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(2, Client::count());

        $this->artisan('portal:purge-client', ['client' => 'keronlewis@live.com', '--force' => true])
            ->expectsQuestion('Type the company name exactly to confirm', 'Keron Lewis')->expectsOutputToContain('Nothing was deleted')->assertFailed();
        $this->assertSame(2, Client::count());

        $this->artisan('portal:purge-client', ['client' => (string) $doomed->id, '--force' => true])
            ->expectsQuestion('Type the company name exactly to confirm', 'Keron Lewis test')->assertSuccessful();

        $this->assertSame([$kept->id], Client::pluck('id')->all());
        foreach ([User::class, Contract::class, Quotation::class, Invoice::class, Payment::class, CardCheckout::class] as $model) {
            $this->assertSame(1, $model::count(), "only the other client's {$model} remains");
        }
        $this->assertSame(2, LineItem::count(), "the other client's quotation and invoice lines remain");
        $this->assertSame(0, ActivityLog::where('subject_type', (new Contract)->getMorphClass())->whereNotIn('subject_id', Contract::pluck('id'))->count());
        $this->assertSame(0, DB::table('contract_versions')->whereNotIn('contract_id', Contract::pluck('id'))->count());
        $this->assertSame(['real@client.test'], DB::table('invite_tokens')->pluck('email')->all());
        Storage::disk('local')->assertMissing("signatures/{$doomed->id}.png");
        Storage::disk('local')->assertMissing("payments/{$doomed->id}.png");
        Storage::disk('local')->assertExists("signatures/{$kept->id}.png");
    }

    public function test_an_unknown_or_ambiguous_client_is_refused(): void
    {
        $this->artisan('portal:purge-client', ['client' => 'nobody@example.test'])->expectsOutputToContain('No client matches')->assertFailed();
    }
}
