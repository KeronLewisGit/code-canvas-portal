<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Quotation;
use App\Services\QuotationWorkflow;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts a prepared proposal on a client's profile as a draft quotation and a draft contract that carries the agreed
 * special terms. Nothing is emailed and the client sees neither until you send them. Safe on production.
 */
class DraftProposalCommand extends Command
{
    protected $signature = 'portal:draft-proposal {proposal : Name of a file in database/proposals, e.g. mbe-cayman} {--client= : Part of the client\'s company name, if different from the proposal file} {--create-client : Create the client when none matches} {--email= : Contact email, needed with --create-client}';

    protected $description = 'Draft a quotation and contract for a client from a proposal file, without sending anything';

    public function handle(QuotationWorkflow $workflow): int
    {
        $file = database_path('proposals/'.basename((string) $this->argument('proposal')).'.php');
        if (! is_file($file)) {
            $this->error('No proposal file at '.$file);

            return self::FAILURE;
        }
        /** @var array<string, mixed> $proposal */
        $proposal = require $file;

        $client = $this->client($proposal['client']);
        if (! $client) {
            return self::FAILURE;
        }

        $q = $proposal['quotation'];
        if (Quotation::where('client_id', $client->id)->where('title', $q['title'])->exists()) {
            $this->warn($client->company.' already has a quotation titled "'.$q['title'].'". Nothing was changed.');

            return self::FAILURE;
        }

        [$quotation, $contract] = DB::transaction(function () use ($client, $q, $proposal, $workflow) {
            $quotation = Quotation::create([
                'client_id' => $client->id, 'title' => $q['title'], 'issue_date' => now(), 'valid_until' => now()->addDays($q['valid_days']),
                'currency' => $q['currency'], 'tax_rate' => config('company.tax_rate'), 'status' => 'draft', 'intro' => $q['intro'], 'notes' => $q['notes'],
                'contract_type' => $q['contract_type'], 'billing_cycle' => $q['billing_cycle'], 'auto_send_contract' => false,
            ]);
            $quotation->syncItems($q['items']);

            $contract = $workflow->createContract($quotation->fresh());
            $c = $proposal['contract'];
            $contract->forceFill([
                'start_date' => now()->startOfDay()->addDays($c['start_in_days']), 'notice_period_days' => $c['notice_period_days'],
                'payment_terms_days' => $c['payment_terms_days'], 'deposit_percent' => $c['deposit_percent'], 'scope' => $c['scope'], 'terms' => $c['terms'],
            ])->save();
            Audit::log($contract, 'special_terms_added', ['from_proposal' => $this->argument('proposal')]);

            return [$quotation, $contract];
        });

        $this->info('Drafted for '.$client->company.':');
        $this->line('  Quotation '.$quotation->reference.' ('.money($quotation->total, $quotation->currency).' per '.$contract->cycle()['per'].'), draft, not sent');
        $this->line('  Contract  '.$contract->reference.', draft, not sent for signature');
        $this->line('Review them at '.route('admin.contracts.show', $contract).' and set the real launch date as the contract start before sending.');

        return self::SUCCESS;
    }

    /** @param array<string, string> $spec */
    private function client(array $spec): ?Client
    {
        $search = $this->option('client') ?: $spec['search'];
        $matches = Client::where('company', 'like', '%'.$search.'%')->get();

        if ($matches->count() > 1) {
            $this->error('More than one client matches "'.$search.'". Narrow it with --client=:');
            $matches->each(fn (Client $c) => $this->line('  #'.$c->id.' '.$c->company));

            return null;
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if (! $this->option('create-client')) {
            $this->error('No client matches "'.$search.'". Check the name with --client=, or add --create-client --email=<contact email> to create it.');

            return null;
        }
        if (! $this->option('email')) {
            $this->error('--create-client needs --email=<contact email> so the client has a valid contact.');

            return null;
        }

        return Client::create(['type' => 'company', 'company' => $spec['company'], 'contact_name' => $spec['contact_name'], 'email' => $this->option('email'), 'website' => $spec['website'] ?? null, 'active' => true]);
    }
}
