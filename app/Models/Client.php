<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $company
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $website
 * @property string|null $notes
 * @property bool $active
 * @property Carbon|null $disclosure_consent_at
 */
#[Fillable(['company', 'contact_name', 'email', 'phone', 'address', 'website', 'notes', 'active', 'disclosure_consent_at'])]
class Client extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'disclosure_consent_at' => 'datetime'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest('start_date');
    }

    /** @return HasMany<Quotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->latest('issue_date');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issue_date');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on');
    }

    public function hasDisclosureConsent(): bool
    {
        return $this->disclosure_consent_at !== null;
    }

    /** Total still owed across active and completed contracts (verified payments only). */
    public function outstanding(): float
    {
        return self::outstandingOf($this->contracts);
    }

    /** Same figure from an already-loaded set of contracts. */
    public static function outstandingOf(Collection $contracts): float
    {
        return round($contracts->whereIn('status', ['active', 'completed'])->sum(fn (Contract $c) => $c->balance()), 2);
    }
}
