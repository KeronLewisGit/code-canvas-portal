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
 * @property int|null $quotation_id
 * @property string $title
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property string $currency
 * @property string $tax_rate
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $reminded_at
 * @property-read Client $client
 */
#[Fillable(['reference', 'client_id', 'contract_id', 'quotation_id', 'title', 'issue_date', 'due_date', 'currency', 'tax_rate', 'status', 'notes', 'sent_at', 'paid_at', 'reminded_at', 'kind'])]
class Invoice extends Model
{
    use DateRules, HasLineItems, HasReference;

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime', 'reminded_at' => 'datetime', 'subtotal' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];
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

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on');
    }

    public function statusName(): string
    {
        return config("company.invoice_statuses.{$this->effectiveStatus()}", ucfirst($this->status));
    }

    /** Sent invoices past due read as overdue without a cron. */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, ['sent', 'partial']) && self::dateHasPassed($this->due_date)) {
            return 'overdue';
        }

        return $this->status;
    }

    /** "Net 14" from the gap between issue and due dates, or "Due on receipt". */
    public function termsLabel(): string
    {
        if (! $this->due_date) {
            return 'Due on receipt';
        }
        $days = (int) $this->issue_date->diffInDays($this->due_date);

        return $days <= 0 ? 'Due on receipt' : 'Net '.$days;
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', 'verified')->sum('amount');
    }

    public function balance(): float
    {
        return max(0, (float) $this->total - $this->paidAmount());
    }

    /** Issued, not settled, and something still owed. */
    public function acceptsPayments(): bool
    {
        return in_array($this->status, ['sent', 'partial']) && $this->balance() > 0;
    }

    /** Called after a payment is verified or rejected. */
    public function refreshPaymentStatus(): void
    {
        if (in_array($this->status, ['draft', 'cancelled'])) {
            return;
        }
        if ($this->status === 'paid' && $this->payments()->doesntExist()) {
            return; // settled outside the portal and marked paid by hand
        }
        $paid = $this->paidAmount();
        if ($paid + 0.005 >= (float) $this->total && $this->total > 0) {
            $this->forceFill(['status' => 'paid', 'paid_at' => $this->paid_at ?? now()])->save();
        } elseif ($paid > 0) {
            $this->forceFill(['status' => 'partial', 'paid_at' => null])->save();
        } else {
            $this->forceFill(['status' => 'sent', 'paid_at' => null])->save();
        }
    }
}
