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

    /** Contracts the client can pay against right now. The single rule behind every payment option in the portal. */
    public function payableContracts(): Collection
    {
        return $this->contracts()->whereIn('status', Contract::PAYABLE_STATUSES)->get()->filter->acceptsPayments()->values();
    }

    /** Invoices the client can pay against right now. */
    public function payableInvoices(): Collection
    {
        return $this->invoices()->whereIn('status', ['sent', 'partial'])->get()->filter->acceptsPayments()->values();
    }

    /** Payment options are shown only once there is an issued contract or invoice with something owed. */
    public function hasPayables(): bool
    {
        return $this->payableInvoices()->isNotEmpty() || $this->payableContracts()->isNotEmpty();
    }

    public function hasDisclosureConsent(): bool
    {
        return $this->disclosure_consent_at !== null;
    }

    /**
     * What the client owes right now (verified payments deducted): the balance of every contract issued to them,
     * plus open invoices that are not tied to a contract. Invoices on a contract are covered by its balance.
     */
    public function outstanding(): float
    {
        $contracts = $this->contracts()->whereIn('status', Contract::PAYABLE_STATUSES)->get()->filter->isIssued()->sum(fn (Contract $c) => $c->balance());
        $looseInvoices = $this->invoices()->whereNull('contract_id')->whereIn('status', ['sent', 'partial'])->get()->sum(fn (Invoice $i) => $i->balance());

        return round($contracts + $looseInvoices, 2);
    }
}
