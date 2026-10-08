<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company', 'contact_name', 'email', 'phone', 'address', 'website', 'notes', 'active', 'disclosure_consent_at'])]
class Client extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'disclosure_consent_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest('start_date');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->latest('issue_date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issue_date');
    }

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
        return $this->contracts()->whereIn('status', ['active', 'completed'])->get()->sum(fn (Contract $c) => $c->balance());
    }
}
