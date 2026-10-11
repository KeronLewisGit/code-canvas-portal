<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates portal logins and issues passwords. The plain password exists only for this request, so it is
 * returned for the one-time admin banner and, when asked, emailed straight away.
 */
class PortalAccess
{
    /** @return array{user: User, password: string, emailed: bool} */
    public function createLogin(Client $client, string $name, string $email, ?string $password = null, bool $sendEmail = true): array
    {
        $password = $password ?: $this->generatePassword();
        $user = $client->users()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'client']);

        return ['user' => $user, 'password' => $password, 'emailed' => $sendEmail && app(Notifier::class)->loginDetails($user, $password, isNew: true)];
    }

    /** A fresh password replaces the old one at once; the client is nudged to choose their own again. */
    public function resetPassword(User $user, bool $sendEmail = false): array
    {
        $password = $this->generatePassword();
        $user->forceFill(['password' => $password, 'password_changed_at' => null])->save();

        return ['user' => $user, 'password' => $password, 'emailed' => $sendEmail && app(Notifier::class)->loginDetails($user, $password, isNew: false)];
    }

    /** Flash data for the one-time credentials banner in the layout. */
    public static function banner(array $result): array
    {
        return ['email' => $result['user']->email, 'password' => $result['password'], 'emailed' => $result['emailed']];
    }

    private function generatePassword(): string
    {
        return Str::password(14, symbols: false);
    }
}
