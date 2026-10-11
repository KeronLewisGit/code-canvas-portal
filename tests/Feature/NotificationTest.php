<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentReminders;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $this->client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->clientUser = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $this->client->id]);
    }

    public function test_sending_an_invoice_emails_the_client_but_not_the_admin_who_sent_it(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Deposit', 'issue_date' => now(), 'due_date' => now()->addDays(14), 'status' => 'draft']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 500]]);
        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'sent'])->assertRedirect();

        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_contains($n->subject, $invoice->reference) && str_contains($n->subject, 'TT$500.00'));
        Notification::assertNotSentTo($this->admin, PortalMail::class); // they did it themselves

        // An invoice sent by the system (e.g. a recurring period) is copied to the admin.
        auth()->logout();
        app(Notifier::class)->invoiceSent($invoice->fresh(['client.users']));
        Notification::assertSentTo($this->admin, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Sent: invoice'));
        $this->actingAs($this->admin);
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 2);

        // Marking it sent again does not re-send.
        $this->actingAs($this->admin)->patch(route('admin.invoices.status', $invoice), ['status' => 'sent']);
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 2);
    }

    public function test_client_without_a_login_is_emailed_at_the_contact_address(): void
    {
        $loginless = Client::create(['company' => 'Solo', 'contact_name' => 'Sam', 'email' => 'sam@solo.test']);
        $quotation = Quotation::create(['client_id' => $loginless->id, 'title' => 'Site', 'issue_date' => now(), 'status' => 'draft']);
        $this->actingAs($this->admin)->patch(route('admin.quotations.status', $quotation), ['status' => 'sent']);
        Notification::assertSentOnDemand(PortalMail::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === ['sam@solo.test' => 'Sam']);
    }

    public function test_payment_flow_notifies_each_side_at_the_right_moment(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'active']);
        $this->actingAs($this->clientUser)->post(route('portal.payments.store'), ['contract_id' => $contract->id, 'amount' => 400, 'method' => 'cash', 'paid_on' => now()->format('Y-m-d'), 'reference' => 'Handed over'])->assertRedirect();
        Notification::assertSentTo($this->admin, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Payment to verify'));
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_contains($n->subject, 'received your payment'));

        $payment = Payment::first();
        $this->actingAs($this->admin)->patch(route('admin.payments.reject', $payment), ['rejection_reason' => 'Nothing received']);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_contains($n->subject, 'could not verify') && in_array('Reason: Nothing received', $n->lines));

        $this->actingAs($this->admin)->patch(route('admin.payments.verify', $payment));
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Receipt RCT-'.now()->format('Y').'-001: TT$400.00 received') && $n->actionUrl === route('documents.receipt', $payment));
    }

    public function test_contract_signature_request_and_signing_notify_both_sides(): void
    {
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'Site', 'type' => 'website', 'start_date' => now(), 'amount' => 1000, 'status' => 'draft']);
        $this->actingAs($this->admin)->patch(route('admin.contracts.signature', $contract), ['action' => 'request']);
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_contains($n->subject, 'ready to sign'));

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $this->actingAs($this->clientUser)->post(route('portal.contracts.sign.store', $contract), ['signed_name' => 'Ann', 'agree' => 1, 'signature' => $png]);
        Notification::assertSentTo($this->admin, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Signed: contract'));
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Signed copy'));
    }

    public function test_manual_reminders_are_stamped_logged_and_limited_to_open_items(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'title' => 'Late', 'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(10), 'status' => 'sent']);
        $invoice->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 300]]);
        $this->actingAs($this->admin)->post(route('admin.invoices.remind', $invoice))->assertRedirect();
        Notification::assertSentTo($this->clientUser, PortalMail::class, fn ($n) => str_starts_with($n->subject, 'Reminder: invoice') && str_contains($n->lines[0], 'was due on'));
        $this->assertNotNull($invoice->fresh()->reminded_at);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $invoice->id, 'action' => 'reminder_sent']);

        $paid = Invoice::create(['client_id' => $this->client->id, 'title' => 'Paid', 'issue_date' => now(), 'status' => 'paid']);
        $this->actingAs($this->admin)->post(route('admin.invoices.remind', $paid))->assertForbidden();

        $quotation = Quotation::create(['client_id' => $this->client->id, 'title' => 'Q', 'issue_date' => now(), 'valid_until' => now()->addWeek(), 'status' => 'sent']);
        $this->actingAs($this->admin)->post(route('admin.quotations.remind', $quotation))->assertRedirect();
        $contract = Contract::create(['client_id' => $this->client->id, 'title' => 'C', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'approved', 'sent_for_signature_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.contracts.remind', $contract))->assertRedirect();
        $this->actingAs($this->clientUser)->post(route('admin.contracts.remind', $contract))->assertForbidden();
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 3);
    }

    public function test_scheduled_overdue_reminders_run_at_most_weekly(): void
    {
        $overdue = Invoice::create(['client_id' => $this->client->id, 'title' => 'Late', 'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(3), 'status' => 'sent']);
        $overdue->syncItems([['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]]);
        $recent = Invoice::create(['client_id' => $this->client->id, 'title' => 'Recently reminded', 'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(3), 'status' => 'sent', 'reminded_at' => now()->subDays(2)]);
        Invoice::create(['client_id' => $this->client->id, 'title' => 'Not due', 'issue_date' => now(), 'due_date' => now()->addDays(5), 'status' => 'sent']);

        (new SendPaymentReminders)->handle(app(Notifier::class));
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
        $this->assertNotNull($overdue->fresh()->reminded_at);
        $this->assertTrue($recent->fresh()->reminded_at->lt(now()->subDay()), 'recently reminded invoice is left alone');
    }

    public function test_creating_a_login_emails_a_set_password_link_never_a_password(): void
    {
        $this->actingAs($this->admin)->post(route('admin.clients.users.store', $this->client), ['name' => 'Bob', 'email' => 'bob@acme.test', 'send_email' => '1'])
            ->assertRedirect()->assertSessionHas('saved', 'Portal login created. A link to set their password was emailed to bob@acme.test.');
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        Notification::assertSentTo($bob, PortalMail::class, fn (PortalMail $mail) => str_contains($mail->subject, 'client portal is ready')
            && $mail->actionLabel === 'Set your password' && str_contains($mail->actionUrl, '/set-password/')
            && ! collect($mail->lines)->contains(fn ($l) => str_contains($l, 'Password:')));
        Notification::assertNotSentTo($this->clientUser, PortalMail::class);
        $this->assertNull(session('credentials'), 'no banner once the link is emailed');

        $this->setPasswordFromEmail($bob, 'bobs-own-password');
        $this->assertTrue(password_verify('bobs-own-password', $bob->fresh()->password));
        $this->assertNotNull($bob->fresh()->password_changed_at, 'no temporary-password banner afterwards');

        $this->actingAs($this->admin)->post(route('admin.clients.users.store', $this->client), ['name' => 'Cy', 'email' => 'cy@acme.test', 'password' => 'chosen-password-1'])
            ->assertRedirect()->assertSessionHas('saved', 'Portal login created.');
        Notification::assertNotSentTo(User::where('email', 'cy@acme.test')->firstOrFail(), PortalMail::class);
        $this->assertSame('chosen-password-1', session('credentials')['password']);
    }

    public function test_email_link_lets_the_client_choose_a_new_password_and_reset_shows_one_to_pass_on(): void
    {
        $original = $this->clientUser->password;
        $this->actingAs($this->admin)->patch(route('admin.clients.users.update', [$this->client, $this->clientUser]), ['send_email' => '1'])
            ->assertRedirect()->assertSessionHas('saved', 'A link to choose a new password was emailed to ann@acme.test.')->assertSessionMissing('credentials');
        $this->assertSame($original, $this->clientUser->fresh()->password, 'the current password keeps working until they choose one');
        $this->setPasswordFromEmail($this->clientUser, 'anns-new-password');
        $this->assertTrue(password_verify('anns-new-password', $this->clientUser->fresh()->password));

        $this->actingAs($this->admin)->patch(route('admin.clients.users.update', [$this->client, $this->clientUser]))->assertSessionHas('saved', 'Password reset. Pass the new one on to the client.');
        $this->assertTrue(password_verify(session('credentials')['password'], $this->clientUser->fresh()->password));
        Notification::assertSentToTimes($this->clientUser, PortalMail::class, 1);
    }

    public function test_a_set_password_link_works_once_and_expires_after_a_week(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.clients.users.update', [$this->client, $this->clientUser]), ['send_email' => '1']);
        $url = Notification::sent($this->clientUser, PortalMail::class)->last()->actionUrl;
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        auth()->logout();
        $post = fn () => $this->post(route('password.invite.update'), ['token' => $token, 'email' => 'ann@acme.test', 'password' => 'anns-new-password', 'password_confirmation' => 'anns-new-password']);

        $this->travel(8)->days();
        $post()->assertSessionHasErrors(['email' => 'This link has expired or was already used. Use "Forgot password?" below to get a new one.']);
        $this->travelBack();
        $post()->assertRedirect(route('login'));
        $post()->assertSessionHasErrors('email');
    }

    public function test_emails_carry_the_code_canvas_logo_footer_and_website_link(): void
    {
        $html = (string) (new PortalMail('Hello', ['A line.'], 'Open', route('login')))->toMail($this->clientUser)->render();

        $this->assertStringContainsString(asset('images/brand/logo-email.png'), $html);
        $this->assertStringContainsString('alt="Code Canvas Consultants Ltd"', $html);
        $this->assertStringContainsString('© '.date('Y').' Code Canvas Consultants Ltd. All rights reserved.', $html);
        $this->assertStringContainsString('href="https://codecanvastt.com"', $html);
        $this->assertStringNotContainsString('Code Canvas Portal. All rights reserved', $html);
    }

    /** The password as it appeared in the email sent to this user. */
    /** Follow the "Set your password" link from the last email to this user and choose $password. */
    private function setPasswordFromEmail(User $user, string $password): void
    {
        $url = Notification::sent($user, PortalMail::class)->last()->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Set your password');
        $this->post(route('password.invite.update'), ['token' => $token, 'email' => $query['email'], 'password' => $password, 'password_confirmation' => $password])
            ->assertRedirect(route('login'))->assertSessionHas('status');
    }
}
