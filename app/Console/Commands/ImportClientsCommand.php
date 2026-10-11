<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\PortalAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports clients from a contacts CSV exported from the old system (Zoho Books/Invoice format: Display Name,
 * Company Name, First Name, Last Name, Phone, MobilePhone, Website, Status, Customer Sub Type, EmailID), gives each
 * a portal login, and emails them the new-platform welcome with a link to choose their password.
 *
 * Without --force it only shows what it would do. Safe to run again: clients and logins already there are skipped.
 */
class ImportClientsCommand extends Command
{
    protected $signature = 'portal:import-clients {file : Path to the contacts CSV} {--force : Import (otherwise this is a preview)} {--no-email : Import without sending the welcome emails}';

    protected $description = 'Import clients from a contacts CSV, create their portal logins and email the welcome';

    public function handle(PortalAccess $access): int
    {
        $rows = $this->readCsv((string) $this->argument('file'));
        if ($rows === null) {
            return self::FAILURE;
        }

        $plan = $this->plan($rows);
        $this->table(['Company', 'Type', 'Contact', 'Email', 'What happens'], array_map(fn ($p) => [$p['company'], $p['type'], $p['contact'], $p['email'] ?: '—', $p['note']], $plan));
        $creating = array_filter($plan, fn ($p) => $p['create']);
        $logins = array_filter($creating, fn ($p) => $p['login']);
        $this->line(count($creating).' client(s) to import, '.count($logins).' with a portal login'.($this->option('no-email') ? '.' : ' and a welcome email.'));

        if (! $this->option('force')) {
            $this->info('Preview only: nothing was imported or emailed. Add --force to do it.');

            return self::SUCCESS;
        }

        $created = [];
        foreach ($creating as $p) {
            $created[] = DB::transaction(function () use ($p, $access) {
                $client = Client::create(['type' => $p['type'], 'company' => $p['company'], 'contact_name' => $p['contact'], 'email' => $p['email'], 'phone' => $p['phone'], 'website' => $p['website'], 'active' => $p['active'], 'notes' => $p['notes']]);
                $user = $p['login'] ? $access->createLogin($client, $p['contact'], $p['email'], sendEmail: false)['user'] : null;

                return [$client, $user];
            });
        }
        $this->info('Imported '.count($created).' client(s).');

        if ($this->option('no-email')) {
            return self::SUCCESS;
        }
        $sent = $failed = 0;
        foreach ($created as [$client, $user]) {
            if (! $user) {
                continue;
            }
            if ($access->sendMigrationWelcome($user)) {
                $sent++;
                $this->line("  welcome sent to {$user->email}");
            } else {
                $failed++;
                $this->warn("  could not email {$user->email}; send it later with the \"Email link\" button on their client page");
            }
            usleep(500_000); // gentle on the mail server
        }
        $this->info("Welcome emails: {$sent} sent".($failed ? ", {$failed} failed" : '').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array<string, string>>|null */
    private function readCsv(string $path): ?array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return null;
        }
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header ?: []);
        foreach (['Display Name', 'EmailID', 'Customer Sub Type'] as $required) {
            if (! in_array($required, $header, true)) {
                $this->error("This does not look like the contacts export: the \"{$required}\" column is missing.");

                return null;
            }
        }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Maps each contact to the portal's fields and decides what happens to it.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>>
     */
    private function plan(array $rows): array
    {
        // Contacts sharing an email are one client (e.g. a person also listed under the business they pay for):
        // keep the individual if there is one, otherwise the first, and note the other names on the kept client.
        $byEmail = [];
        foreach ($rows as $i => $r) {
            $email = strtolower(trim((string) ($r['EmailID'] ?? '')));
            if ($email !== '') {
                $byEmail[$email][] = $i;
            }
        }
        $keep = $alsoKnownAs = $duplicateOf = [];
        foreach ($byEmail as $indexes) {
            $individual = array_values(array_filter($indexes, fn ($i) => strtolower(trim((string) ($rows[$i]['Customer Sub Type'] ?? ''))) === 'individual'));
            $kept = $individual[0] ?? $indexes[0];
            $keep[$kept] = true;
            foreach ($indexes as $i) {
                if ($i !== $kept) {
                    $alsoKnownAs[$kept][] = trim((string) ($rows[$i]['Display Name'] ?? ''));
                    $duplicateOf[$i] = $kept;
                }
            }
        }

        $plan = [];
        $loginEmails = User::pluck('email')->map(fn ($e) => strtolower($e))->flip()->all();
        foreach ($rows as $index => $r) {
            $v = fn (string $key) => trim((string) ($r[$key] ?? ''));
            $individual = strtolower($v('Customer Sub Type')) === 'individual';
            $person = trim($v('First Name').' '.$v('Last Name'));
            $company = $individual ? ($person ?: $v('Display Name')) : ($v('Company Name') ?: $v('Display Name'));
            $email = strtolower($v('EmailID'));
            $website = $v('Website');
            $p = [
                'type' => $individual ? 'individual' : 'company',
                'company' => $company,
                'contact' => $person ?: ($v('Display Name') ?: $company),
                'email' => $email,
                'phone' => ltrim($v('Phone') ?: $v('MobilePhone'), "'") ?: null,
                'website' => filter_var($website, FILTER_VALIDATE_URL) ? $website : null,
                'active' => strtolower($v('Status') ?: 'active') === 'active',
                'notes' => isset($alsoKnownAs[$index]) ? 'Also listed in the old system as: '.implode(', ', array_filter($alsoKnownAs[$index])).'.' : null,
                'create' => false,
                'login' => false,
            ];

            $exists = $email !== '' && Client::where('email', $email)->whereRaw('lower(company) = ?', [strtolower($company)])->exists();
            [$p['create'], $p['login'], $p['note']] = match (true) {
                $company === '' => [false, false, 'Skipped: no name'],
                isset($duplicateOf[$index]) => [false, false, 'Skipped: same email as '.trim((string) ($rows[$duplicateOf[$index]]['Display Name'] ?? '')).' (one client per email)'],
                $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) => [false, false, 'Skipped: no valid email (add this client by hand)'],
                $exists => [false, false, 'Skipped: already in the portal'],
                isset($loginEmails[$email]) => [true, false, 'Import; no login (this email already has one)'],
                ! $p['active'] => [true, false, 'Import as inactive; no login'],
                default => [true, true, 'Import, create login'.($this->option('no-email') ? '' : ', email welcome')],
            };
            if ($p['login']) {
                $loginEmails[$email] = true; // a later contact with the same email shares it and gets no second login
            }
            $plan[] = $p;
        }

        return $plan;
    }
}
