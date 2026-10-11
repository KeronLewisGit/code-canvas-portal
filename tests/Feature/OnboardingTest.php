<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
    }

    private function addClient(array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.clients.store'), $extra + ['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test', 'active' => '1']);
    }

    public function test_adding_a_client_creates_the_login_and_sends_the_welcome_immediately(): void
    {
        $this->addClient(['create_login' => '1'])->assertRedirect()->assertSessionHas('saved', 'Client added and welcome email sent to ann@acme.test.');

        $client = Client::firstWhere('company', 'Acme');
        $user = $client->users()->sole();
        $password = $this->emailedPassword($user);
        $this->assertSame(['ann@acme.test', 'Ann', 'client'], [$user->email, $user->name, $user->role]);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNull(session('credentials'), 'no banner once the details are emailed');

        Notification::assertSentTo($user, PortalMail::class, fn (PortalMail $mail) => str_contains($mail->subject, 'Welcome to the')
            && str_contains($mail->lines[0], 'onboarded') && in_array('Password: '.$password, $mail->lines, true) && $mail->actionUrl === route('login'));
        // Credentials must never sit in the queue: the mail is sent on the spot.
        Notification::assertSentTimes(PortalMail::class, 1);
    }

    public function test_adding_a_client_without_a_login_sends_nothing(): void
    {
        $this->addClient()->assertSessionHas('saved', 'Client added. Create their portal login below when you are ready.');

        $this->assertSame(0, Client::firstWhere('company', 'Acme')->users()->count());
        Notification::assertNothingSent();
    }

    public function test_inactive_clients_get_no_login(): void
    {
        $this->addClient(['create_login' => '1', 'active' => '0']);

        $this->assertSame(0, Client::firstWhere('company', 'Acme')->users()->count());
        Notification::assertNothingSent();
    }

    public function test_an_email_already_used_by_a_login_blocks_the_whole_client(): void
    {
        User::create(['name' => 'Other', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client']);

        $this->addClient(['create_login' => '1'])->assertSessionHasErrors('email');

        $this->assertSame(0, Client::count());
        Notification::assertNothingSent();
    }

    public function test_first_login_shows_the_welcome_then_keeps_the_link_they_followed(): void
    {
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'issued-password', 'role' => 'client', 'client_id' => $client->id]);

        $this->get(route('portal.invoices.index'))->assertRedirect(route('login'));
        $this->post(route('login.attempt'), ['email' => 'ann@acme.test', 'password' => 'issued-password'])->assertRedirect(route('portal.welcome'));
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->get(route('portal.welcome'))->assertOk()->assertSee('Welcome, Ann.')->assertSee('Choose your own password');
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.welcome'));

        $this->post(route('portal.welcome.complete'))->assertRedirect(route('portal.invoices.index'));
        $this->assertNotNull($user->fresh()->welcomed_at);
        $this->assertNull($user->fresh()->password_changed_at);
        $this->get(route('portal.dashboard'))->assertOk()->assertSee('still using the password we emailed you');
    }

    public function test_welcome_can_set_a_password_which_ends_the_reminder(): void
    {
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'issued-password', 'role' => 'client', 'client_id' => $client->id]);

        $this->actingAs($user)->post(route('portal.welcome.complete'), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->welcomed_at);

        $this->post(route('portal.welcome.complete'), ['password' => 'my-own-password', 'password_confirmation' => 'my-own-password'])->assertRedirect(route('portal.dashboard'));
        $user->refresh();
        $this->assertTrue(Hash::check('my-own-password', $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->get(route('portal.dashboard'))->assertOk()->assertDontSee('still using the password we emailed you');

        // Afterwards the guide is read-only: no password form, so a borrowed session cannot change it there.
        $this->get(route('portal.welcome'))->assertOk()->assertSee('Your portal guide')->assertDontSee('Choose your own password');
        $this->post(route('portal.welcome.complete'), ['password' => 'hijacked-password', 'password_confirmation' => 'hijacked-password']);
        $this->assertTrue(Hash::check('my-own-password', $user->fresh()->password));
    }

    public function test_changing_the_password_on_the_account_page_ends_the_reminder_and_an_admin_reset_brings_it_back(): void
    {
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'issued-password', 'role' => 'client', 'client_id' => $client->id]);
        $user->forceFill(['welcomed_at' => now()])->save();

        $this->actingAs($user)->patch(route('account.password'), ['current_password' => 'issued-password', 'password' => 'my-own-password', 'password_confirmation' => 'my-own-password']);
        $this->assertNotNull($user->fresh()->password_changed_at);

        $this->actingAs($this->admin)->patch(route('admin.clients.users.update', [$client, $user]));
        $this->assertNull($user->fresh()->password_changed_at);
    }

    public function test_admins_skip_the_welcome_and_the_password_reminder(): void
    {
        $this->post(route('login.attempt'), ['email' => 'admin@example.com', 'password' => 'secret-password'])->assertRedirect(route('home'));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('still using the password');
    }

    public function test_page_headers_escape_names_once(): void
    {
        $client = Client::create(['company' => 'Crystal & Co.', 'contact_name' => "Ann O'Neil", 'email' => 'ann@acme.test']);
        $user = User::create(['name' => "Ann O'Neil", 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $client->id]);
        $user->forceFill(['welcomed_at' => now()])->save();

        $this->actingAs($user)->get(route('portal.dashboard'))->assertOk()
            ->assertSee('Crystal &amp; Co.', false)->assertDontSee('&amp;amp;', false)->assertDontSee('&amp;#039;', false);
    }

    /** The password as it appeared in the email sent to this user. */
    private function emailedPassword(User $user): string
    {
        $lines = Notification::sent($user, PortalMail::class)->last()->lines;

        return substr(collect($lines)->first(fn ($l) => str_starts_with($l, 'Password: ')), strlen('Password: '));
    }
}
