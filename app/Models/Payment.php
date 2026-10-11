<?php

namespace App\Models;

use App\Services\Notifier;
use App\Support\LedgerCache;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property string|null $receipt_number RCT-2026-001, issued when a payment received is confirmed
 * @property-read Client $client
 */
#[Fillable(['client_id', 'contract_id', 'invoice_id', 'amount', 'currency', 'method', 'paid_on', 'reference', 'proof_path', 'note', 'status', 'rejection_reason', 'submitted_by', 'verified_by', 'verified_at', 'receipt_number'])]
class Payment extends Model
{
    /** Set while saving the change that issued this payment's receipt number, so the receipt is emailed once. */
    public bool $receiptJustIssued = false;

    protected static function booted(): void
    {
        // A confirmed payment gets its receipt number the moment it is confirmed, however that happens (verified by an
        // admin, recorded by an admin, an invoice marked paid, or a card payment), and the client is emailed it.
        static::saving(function (Payment $payment) {
            if ($payment->issuesReceipt() && ! $payment->receipt_number) {
                $payment->receipt_number = Reference::next('receipt', self::class, 'receipt_number');
                $payment->receiptJustIssued = true;
            }
        });
        static::saved(function (Payment $payment) {
            LedgerCache::flush();
            if ($payment->receiptJustIssued) {
                $payment->receiptJustIssued = false; // once, even if this payment is saved again in the same request
                DB::afterCommit(fn () => app(Notifier::class)->receipt($payment->fresh(['client.users', 'invoice', 'contract'])));
            }
        });
        static::deleted(fn () => LedgerCache::flush());
    }

    /** Money actually received and confirmed. Credit notes and refunds are adjustments, not payments received. */
    public function issuesReceipt(): bool
    {
        return $this->status === 'verified' && ! in_array($this->method, ['credit_note', 'refund'], true);
    }

    /** A receipt that was issued but whose payment was later reversed. */
    public function receiptVoided(): bool
    {
        return $this->receipt_number !== null && $this->status !== 'verified';
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
