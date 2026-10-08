<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'contract_id', 'invoice_id', 'amount', 'currency', 'method', 'paid_on', 'reference', 'proof_path', 'note', 'status', 'rejection_reason', 'submitted_by', 'verified_by', 'verified_at'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['paid_on' => 'date', 'verified_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

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

    public static function methodNeedsProof(string $method): bool
    {
        return (bool) config("company.payment_methods.{$method}.proof", false);
    }

    public function markVerified(User $by): void
    {
        $this->forceFill(['status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'rejection_reason' => null])->save();
        $this->invoice?->refreshPaymentStatus();
    }

    public function markRejected(User $by, string $reason): void
    {
        $this->forceFill(['status' => 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'rejection_reason' => $reason])->save();
        $this->invoice?->refreshPaymentStatus();
    }
}
