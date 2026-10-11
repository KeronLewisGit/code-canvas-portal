<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role admin | client
 * @property int|null $client_id
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_seen_at
 * @property string|null $last_activity what they last did in the portal, in plain words
 * @property Carbon|null $welcomed_at
 * @property Carbon|null $password_changed_at
 * @property-read Client|null $client
 */
#[Fillable(['name', 'email', 'password', 'role', 'client_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'welcomed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isClient(): bool
    {
        return $this->role === 'client' && $this->client_id !== null;
    }

    /** Clients see the portal guide on their first sign-in. */
    public function needsWelcome(): bool
    {
        return $this->isClient() && $this->welcomed_at === null;
    }

    /** True while a client still signs in with the password we issued rather than one they chose. */
    public function usesIssuedPassword(): bool
    {
        return $this->isClient() && $this->password_changed_at === null;
    }
}
