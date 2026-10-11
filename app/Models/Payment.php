<?php

namespace App\Models;

use App\Support\LedgerCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property int|null $contract_id
 * @property int|null $invoice_id
 * @property string $amount
 * @property string $currency
 * @property string $method
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $proof_path
 * @property string|null $note
 * @property string $status
 * @property string|null $rejection_reason
 * @property int|null $submitted_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property-read Client $client
 */
#[Fillable(['client_id', 'contract_id', 'invoice_id', 'amount', 'currency', 'method', 'paid_on', 'reference', 'proof_path', 'note', 'status', 'rejection_reason', 'submitted_by', 'verified_by', 'verified_at'])]
class Payment extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => LedgerCache::flush());
        static::deleted(fn () => LedgerCache::flush());
    }

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'verified_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function methodName(): string
    {
        return config("company.payment_methods.{$this->method}.label", ucfirst(str_replace('_', ' ', $this->method)));
    }

    public function statusName(): string
    {
        return config("company.payment_statuses.{$this->status}", ucfirst($this->status));
    }

    /** The payment methods a client may choose (admin-only ones such as "Settled outside the portal" excluded). */
    public static function clientMethods(): array
    {
        return array_filter(config('company.payment_methods'), fn ($m) => empty($m['admin_only']));
    }

    public static function methodNeedsProof(string $method): bool
    {
        return (bool) config("company.payment_methods.{$method}.proof", false);
    }

    public function markVerified(User $by): void
    {
        $this->forceFill(['status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'rejection_reason' => null])->save();
        $this->refreshDocuments();
    }

    public function markRejected(User $by, string $reason): void
    {
        $this->forceFill(['status' => 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'rejection_reason' => $reason])->save();
        $this->refreshDocuments();
    }

    /**
     * Invoice statuses follow payments. A payment on a contract can settle any of its invoices, so all of them
     * are re-derived; a standalone invoice payment refreshes just that invoice.
     */
    public function refreshDocuments(): void
    {
        $contract = $this->contract_id ? Contract::find($this->contract_id) : null;
        $contract ? $contract->refreshInvoiceStatuses() : $this->invoice?->refreshPaymentStatus();
    }
}
