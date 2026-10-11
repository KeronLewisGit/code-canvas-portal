<?php

namespace App\Models;

use App\Models\Concerns\DateRules;
use App\Models\Concerns\HasLineItems;
use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference
 * @property int $client_id
 * @property int|null $contract_id
 * @property string $title
 * @property Carbon $issue_date
 * @property Carbon|null $valid_until
 * @property string $currency
 * @property string $tax_rate
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $status
 * @property string|null $intro
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $reminded_at
 * @property string|null $contract_type
 * @property string|null $billing_cycle
 * @property int|null $contract_term_months
 * @property bool $auto_send_contract
 * @property-read Client $client
 */
#[Fillable(['reference', 'client_id', 'contract_id', 'title', 'issue_date', 'valid_until', 'currency', 'tax_rate', 'status', 'intro', 'notes', 'sent_at', 'accepted_at', 'declined_at', 'reminded_at', 'contract_type', 'billing_cycle', 'contract_term_months', 'auto_send_contract'])]
class Quotation extends Model
{
    use DateRules, HasLineItems, HasReference;

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime', 'reminded_at' => 'datetime', 'auto_send_contract' => 'boolean', 'contract_term_months' => 'integer', 'subtotal' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];
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

    /** @return HasMany<Invoice, $this> */
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
