<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Notifications\PortalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Emailing client logins from the Client activity page. */
class ClientEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ann;

    private User $bo;

    private User $inactive;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'role' => 'admin']);
        $acme = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $this->ann = User::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $acme->id]);
        $this->ann->forceFill(['last_login_at' => now()->subDay()])->save();
        $this->bo = User::create(['name' => 'Bo', 'email' => 'bo@acme.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $acme->id]);
        $old = Client::create(['company' => 'Old Co', 'contact_name' => 'Cy', 'email' => 'cy@old.test', 'active' => false]);
        $this->inactive = User::create(['name' => 'Cy', 'email' => 'cy@old.test', 'password' => 'secret-password', 'role' => 'client', 'client_id' => $old->id]);
    }

    public function test_the_activity_page_offers_emailing(): void
    {
        $this->actingAs($this->admin)->get(route('admin.activity'))->assertOk()
            ->assertSee('Email selected')->assertSee("Email everyone who hasn't signed in (1)", false);
    }

    public function test_the_compose_page_lists_the_ticked_logins(): void
    {
        $this->actingAs($this->admin)->get(route('admin.activity.email', ['users' => [$this->ann->id, $this->bo->id]]))->assertOk()
            ->assertSee('ann@acme.test')->assertSee('bo@acme.test')->assertSee('Send to 2 people');
        $this->get(route('admin.activity.email'))->assertRedirect(route('admin.activity'));
    }

    public function test_a_written_message_is_sent_to_each_login(): void
    {
        $this->actingAs($this->admin)->post(route('admin.activity.email.send'), [
            'users' => [$this->ann->id, $this->bo->id], 'kind' => 'message', 'subject' => 'Office closed Friday',
            'body' => "We are closed on Friday.\n\nWe reopen on Monday.", 'button' => '1',
        ])->assertRedirect(route('admin.activity'))->assertSessionHas('saved', 'Sent 2 emails.');

        foreach ([$this->ann, $this->bo] as $user) {
            Notification::assertSentTo($user, PortalMail::class, function (PortalMail $m) {
                return $m->subject === 'Office closed Friday' && $m->lines === ['We are closed on Friday.', 'We reopen on Monday.'] && $m->actionLabel === 'Open the portal';
            });
        }
    }

    public function test_a_message_needs_a_subject_and_body(): void
    {
        $this->actingAs($this->admin)->post(route('admin.activity.email.send'), ['users' => [$this->ann->id], 'kind' => 'message'])
            ->assertSessionHasErrors(['subject', 'body']);
        Notification::assertNothingSent();
    }

    public function test_the_welcome_can_be_resent_to_everyone_who_has_not_signed_in(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.activity.email', ['never' => 1]))->assertOk();
        $page->assertSee('bo@acme.test')->assertDontSee('ann@acme.test')->assertDontSee('cy@old.test');

        $this->post(route('admin.activity.email.send'), ['users' => [$this->bo->id], 'kind' => 'welcome'])->assertSessionHas('saved', 'Sent 1 email.');

        Notification::assertSentTo($this->bo, PortalMail::class, fn (PortalMail $m) => str_contains($m->subject, 'Welcome to the new') && str_contains($m->actionUrl, '/set-password/'));
        Notification::assertNotSentTo($this->ann, PortalMail::class);
    }

    public function test_inactive_clients_and_admins_are_never_emailed(): void
    {
        $this->actingAs($this->admin)->post(route('admin.activity.email.send'), [
            'users' => [$this->inactive->id, $this->admin->id], 'kind' => 'message', 'subject' => 'Hi', 'body' => 'Hello',
        ])->assertSessionHas('saved', 'Sent 0 emails.');
        Notification::assertNothingSent();
    }

    public function test_clients_cannot_use_it(): void
    {
        $this->actingAs($this->ann)->get(route('admin.activity.email', ['users' => [$this->bo->id]]))->assertForbidden();
        $this->post(route('admin.activity.email.send'), ['users' => [$this->bo->id], 'kind' => 'welcome'])->assertForbidden();
    }
}
