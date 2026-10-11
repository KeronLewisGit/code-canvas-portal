<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates portal logins and issues passwords. The plain password exists only for this request, so it is
 * returned for the one-time admin banner and, when asked, emailed straight away.
 */
class PortalAccess
{
    /**
     * A new login. When emailed, the client gets a "Set your password" link, never a password: the generated one
     * is only shown to the admin if the email could not go out (or was not asked for).
     *
     * @return array{user: User, password: string, emailed: bool}
     */
    public function createLogin(Client $client, string $name, string $email, ?string $password = null, bool $sendEmail = true): array
    {
        $password = $password ?: $this->generatePassword();
        $user = $client->users()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'client']);

        return ['user' => $user, 'password' => $password, 'emailed' => $sendEmail && $this->sendPasswordLink($user, isNew: true)];
    }

    /**
     * Emailed: a link to choose a new password (the current one keeps working until they do).
     * Not emailed: a new password replaces the old one at once and every session ends; the admin passes it on.
     *
     * @return array{user: User, password: string|null, emailed: bool}
     */
    public function resetPassword(User $user, bool $sendEmail = false): array
    {
        if ($sendEmail && $this->sendPasswordLink($user, isNew: false)) {
            return ['user' => $user, 'password' => null, 'emailed' => true];
        }
        $password = $this->generatePassword();
        $user->forceFill(['password' => $password, 'password_changed_at' => null])->save();
        self::signOutEverywhere($user); // whoever had the old password loses access now

        return ['user' => $user, 'password' => $password, 'emailed' => false];
    }

    /** Emails a 7-day "Set your password" link. True when the email went out. */
    public function sendPasswordLink(User $user, bool $isNew): bool
    {
        $url = route('password.invite', ['token' => Password::broker('invites')->createToken($user), 'email' => $user->email]);

        return app(Notifier::class)->loginDetails($user, $url, $isNew);
    }

    /**
     * End the user's sessions on every device (optionally keeping the current one) and invalidate "remember me"
     * cookies. Used when a password changes or a client is deactivated.
     */
    public static function signOutEverywhere(User $user, ?string $keepSessionId = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
        }
    }

    /**
     * Flash data for the one-time credentials banner. Only when the email did not go out (not requested, or it
     * failed) does the admin need to see the password to pass it on; otherwise it is never shown or kept.
     */
    public static function banner(array $result): ?array
    {
        return $result['emailed'] ? null : ['email' => $result['user']->email, 'password' => $result['password']];
    }

    private function generatePassword(): string
    {
        return Str::password(14, symbols: false);
    }
}
