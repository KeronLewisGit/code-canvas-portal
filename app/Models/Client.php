<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type company | individual
 * @property string $company display name: the business, or the person for an individual
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $website
 * @property string|null $notes
 * @property bool $active
 * @property Carbon|null $disclosure_consent_at
 */
#[Fillable(['type', 'company', 'contact_name', 'email', 'phone', 'address', 'website', 'notes', 'active', 'disclosure_consent_at'])]
class Client extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'disclosure_consent_at' => 'datetime'];
    }

    public const TYPES = ['company' => 'Company', 'individual' => 'Individual'];

    public function isIndividual(): bool
    {
        return $this->type === 'individual';
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
    /** @return EloquentCollection<int, Contract> */
    public function payableContracts(): EloquentCollection
    {
        return $this->contracts()->whereIn('status', Contract::PAYABLE_STATUSES)->get()->filter->acceptsPayments()->values();
    }

    /** Invoices the client can pay against right now. */
    /** @return EloquentCollection<int, Invoice> */
    public function payableInvoices(): EloquentCollection
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
     * Due now: what is left to pay on issued invoices (sent or partly paid), per currency. This is what the client
     * has actually been billed, including invoices left open on a terminated contract.
     *
     * @return array<string, float>
     */
    public function dueNow(): array
    {
        return sum_by_currency($this->invoices()->whereIn('status', ['sent', 'partial'])->get(), fn (Invoice $i) => $i->balance());
    }

    /**
     * Remaining on contracts: what the client's issued contracts still owe beyond their open invoices, i.e. not
     * invoiced yet (the balance after a deposit, or recurring periods not billed yet), per currency.
     *
     * @return array<string, float>
     */
    public function remainingOnContracts(): array
    {
        return sum_by_currency($this->payableContracts(), fn (Contract $c) => Contract::notYetInvoiced($c));
    }
}
