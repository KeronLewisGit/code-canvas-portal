<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CardCheckout;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently deletes one client and everything attached to it: portal logins (and their sessions and links),
 * quotations, contracts (with versions, signatures and uploaded files), invoices, line items, payments (with proof
 * files), card checkouts and activity history. Deliberately command-line only: there is no button for this in the
 * portal. Without --force it only shows what would be deleted.
 */
class PurgeClientCommand extends Command
{
    protected $signature = 'portal:purge-client {client : Client id, company name, or the client\'s or a login\'s email} {--force : Actually delete (otherwise this is a dry run)}';

    protected $description = 'Permanently delete a client and all of its records and files (dry run unless --force)';

    public function handle(): int
    {
        $client = $this->resolve((string) $this->argument('client'));
        if (! $client) {
            return self::FAILURE;
        }

        $users = $client->users()->get();
        $contracts = Contract::where('client_id', $client->id)->get();
        $quotations = Quotation::where('client_id', $client->id)->get();
        $invoices = Invoice::where('client_id', $client->id)->get();
        $payments = Payment::where('client_id', $client->id)->get();
        $files = collect()
            ->concat($payments->pluck('proof_path'))
            ->concat($contracts->pluck('signature_path'))
            ->concat($contracts->pluck('file_path'))
            ->filter()->unique()->values();
        $subjects = [
            [Client::class, [$client->id]],
            [User::class, $users->pluck('id')->all()],
            [Contract::class, $contracts->pluck('id')->all()],
            [Quotation::class, $quotations->pluck('id')->all()],
            [Invoice::class, $invoices->pluck('id')->all()],
            [Payment::class, $payments->pluck('id')->all()],
        ];
        $activity = ActivityLog::where(function ($q) use ($subjects) {
            foreach ($subjects as [$type, $ids]) {
                $ids && $q->orWhere(fn ($w) => $w->where('subject_type', (new $type)->getMorphClass())->whereIn('subject_id', $ids));
            }
        });

        $this->warn("Client #{$client->id}: {$client->company} <{$client->email}>");
        $this->table(['What', 'Count'], [
            ['Portal logins', $users->count().($users->isNotEmpty() ? ' ('.$users->pluck('email')->implode(', ').')' : '')],
            ['Quotations', $quotations->count()],
            ['Contracts (with versions)', $contracts->count()],
            ['Invoices', $invoices->count()],
            ['Payments', $payments->count().' ('.money_by_currency(sum_by_currency($payments->where('status', 'verified'), fn ($p) => $p->amount)).' verified)'],
            ['Card checkouts', CardCheckout::where('client_id', $client->id)->count()],
            ['Activity log entries', (clone $activity)->count()],
            ['Uploaded files (proofs, signatures, contracts)', $files->count()],
        ]);

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was deleted. Add --force to delete all of the above permanently.');

            return self::SUCCESS;
        }
        $this->error('This cannot be undone. Take a backup first (bash scripts/backup.sh).');
        if ($this->ask('Type the company name exactly to confirm') !== $client->company) {
            $this->info('Name did not match. Nothing was deleted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($client, $users, $quotations, $invoices, $activity) {
            $activity->delete();
            LineItem::where(fn ($q) => $q
                ->where(fn ($w) => $w->where('itemable_type', (new Quotation)->getMorphClass())->whereIn('itemable_id', $quotations->pluck('id')))
                ->orWhere(fn ($w) => $w->where('itemable_type', (new Invoice)->getMorphClass())->whereIn('itemable_id', $invoices->pluck('id'))))->delete();
            // Contracts, quotations, invoices, payments and card checkouts go with the client (cascading keys);
            // deleting them explicitly first keeps this correct even where a key does not cascade.
            CardCheckout::where('client_id', $client->id)->delete();
            Payment::where('client_id', $client->id)->delete();
            Invoice::where('client_id', $client->id)->delete();
            Quotation::where('client_id', $client->id)->delete();
            Contract::where('client_id', $client->id)->delete();
            foreach ($users as $user) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('invite_tokens')->where('email', $user->email)->delete();
                $user->delete();
            }
            $client->delete();
        });
        // Files only once the records are gone, so a failed delete never leaves records pointing at missing files.
        $missing = $files->reject(fn ($path) => Storage::disk('local')->delete($path))->count();
        Log::warning('Client purged from the command line', ['client_id' => $client->id, 'files' => $files->count()]);

        $this->info("Deleted {$client->company} and all of its records".($files->isNotEmpty() ? ' and files' : '').'.'.($missing ? " {$missing} file(s) were already missing." : ''));

        return self::SUCCESS;
    }

    private function resolve(string $needle): ?Client
    {
        $matches = ctype_digit($needle)
            ? Client::whereKey((int) $needle)->get()
            : Client::where('company', $needle)->orWhere('email', $needle)
                ->orWhereIn('id', User::where('email', $needle)->whereNotNull('client_id')->pluck('client_id'))->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            $this->error("No client matches \"{$needle}\". Use the client id from the admin URL (/admin/clients/<id>).");
        } else {
            $this->error("More than one client matches \"{$needle}\"; use the id instead:");
            $this->table(['Id', 'Company', 'Email'], $matches->map(fn ($c) => [$c->id, $c->company, $c->email])->all());
        }

        return null;
    }
}
