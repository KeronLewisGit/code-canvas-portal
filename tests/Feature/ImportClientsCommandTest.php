<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ImportClientsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->csv = base_path('tests/fixtures/contacts.csv');
    }

    public function test_a_preview_changes_nothing(): void
    {
        $this->artisan('portal:import-clients', ['file' => $this->csv])->expectsOutputToContain('Preview only')->assertSuccessful();

        $this->assertSame(0, Client::count());
        Notification::assertNothingSent();
    }

    public function test_it_imports_clients_with_logins_and_emails_the_new_platform_welcome(): void
    {
        $this->artisan('portal:import-clients', ['file' => $this->csv, '--force' => true])->assertSuccessful();

        // 5 imported: the No Email row has no email and is skipped.
        $this->assertSame(5, Client::count());
        $this->assertNull(Client::firstWhere('company', 'No Email Ltd.'));

        $bright = Client::firstWhere('company', 'Bright Bakes');
        $this->assertSame(['company', 'Ada Lovell', 'info@brightbakes.example', true], [$bright->type, $bright->contact_name, $bright->email, $bright->active]);
        $harbour = Client::firstWhere('company', 'Harbour Tours');
        $this->assertSame(['Jo Mills', 'https://harbour.example'], [$harbour->contact_name, $harbour->website], 'blank Company Name falls back to the Display Name');
        $kim = Client::firstWhere('company', 'Kim Ramos');
        $this->assertSame('individual', $kim->type);
        $chef = Client::firstWhere('company', 'Chef Pat Joseph');
        $this->assertSame(['Pat Joseph', '18685550111'], [$chef->contact_name, $chef->phone]);

        // Shared email: one login (the first contact), the other client is imported without one.
        $this->assertSame(1, $kim->users()->count());
        $this->assertSame(0, Client::firstWhere('company', 'Ramos Villa')->users()->count());
        $this->assertSame(4, User::where('role', 'client')->count());

        $user = User::firstWhere('email', 'info@brightbakes.example');
        Notification::assertSentTo($user, PortalMail::class, function (PortalMail $m) {
            $text = implode(' ', $m->lines);

            return $m->subject === 'Welcome to the new Code Canvas client portal' && $m->actionLabel === 'Set your password' && str_contains($m->actionUrl, '/set-password/')
                && str_contains($text, 'moving our client records') && str_contains($text, 'Quotations:') && str_contains($text, 'Contracts:')
                && str_contains($text, 'Invoices:') && str_contains($text, 'Payments:') && str_contains($text, 'Receipts and statements:') && ! str_contains($text, 'Password:');
        });
        Notification::assertSentTimes(PortalMail::class, 4);
    }

    public function test_the_welcome_mentions_card_payments_when_wipay_is_on(): void
    {
        config(['company.wipay.enabled' => true]);

        $this->artisan('portal:import-clients', ['file' => $this->csv, '--force' => true])->assertSuccessful();

        Notification::assertSentTo(User::firstWhere('email', 'hello@harbour.example'), PortalMail::class, fn (PortalMail $m) => str_contains(implode(' ', $m->lines), 'pay your invoices online by card'));
    }

    public function test_running_it_again_skips_what_is_already_there_and_no_email_imports_quietly(): void
    {
        $this->artisan('portal:import-clients', ['file' => $this->csv, '--force' => true, '--no-email' => true])->assertSuccessful();
        Notification::assertNothingSent();

        $this->artisan('portal:import-clients', ['file' => $this->csv, '--force' => true])->expectsOutputToContain('already in the portal')->assertSuccessful();

        $this->assertSame(5, Client::count());
        Notification::assertNothingSent();
    }

    public function test_a_file_that_is_not_the_contacts_export_is_refused(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($bad, "Name,Email\nA,a@b.test\n");

        $this->artisan('portal:import-clients', ['file' => $bad])->expectsOutputToContain('does not look like the contacts export')->assertFailed();
    }
}
