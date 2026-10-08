<?php

namespace App\Models;

use App\Models\Concerns\DateRules;
use App\Models\Concerns\HasLineItems;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'client_id', 'contract_id', 'title', 'issue_date', 'valid_until', 'currency', 'tax_rate', 'status', 'intro', 'notes', 'sent_at', 'accepted_at', 'declined_at', 'reminded_at'])]
class Quotation extends Model
{
    use DateRules, HasLineItems;

    protected static function booted(): void
    {
        static::creating(fn (Quotation $q) => $q->reference ??= Reference::next('quotation', static::class));
    }

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime', 'reminded_at' => 'datetime', 'subtotal' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function statusName(): string
    {
        return config("company.quotation_statuses.{$this->status}", ucfirst($this->status));
    }

    /** Sent quotes past their valid-until date read as expired without a cron. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'sent' && self::dateHasPassed($this->valid_until)) {
            return 'expired';
        }

        return $this->status;
    }

    public function canBeAnswered(): bool
    {
        return $this->effectiveStatus() === 'sent';
    }
}
