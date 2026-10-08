<?php

namespace App\Models;

use App\Models\Concerns\DateRules;
use App\Services\ContractFinance;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

#[Fillable(['reference', 'client_id', 'title', 'type', 'start_date', 'end_date', 'amount', 'billing_cycle', 'currency', 'status', 'scope', 'terms', 'file_path', 'standard_terms', 'signed_at', 'signature_path', 'signed_name', 'signed_ip', 'signed_by', 'sent_for_signature_at', 'payment_terms_days', 'notice_period_days', 'auto_renew', 'renewal_of_id', 'client_signatory', 'company_signatory', 'confidential', 'version', 'approved_at', 'approved_by', 'activated_at', 'terminated_at', 'termination_reason', 'reminded_at'])]
class Contract extends Model
{
    use DateRules;

    /** Mirror the database defaults so a freshly created model behaves like a reloaded one. */
    protected $attributes = ['status' => 'draft', 'billing_cycle' => 'one_time', 'currency' => 'TTD', 'payment_terms_days' => 14, 'auto_renew' => false, 'confidential' => false, 'version' => 1];

    protected static function booted(): void
    {
        static::creating(function (Contract $contract) {
            $contract->reference ??= Reference::next('contract', static::class);
        });
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'signed_at' => 'datetime', 'sent_for_signature_at' => 'datetime', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'terminated_at' => 'datetime', 'reminded_at' => 'datetime', 'auto_renew' => 'boolean', 'confidential' => 'boolean', 'standard_terms' => 'array', 'amount' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issue_date');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at');
    }

    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'renewal_of_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Contract::class, 'renewal_of_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Material terms that are versioned on amendment. */
    public const MATERIAL_FIELDS = ['title', 'type', 'start_date', 'end_date', 'amount', 'billing_cycle', 'currency', 'payment_terms_days', 'notice_period_days', 'auto_renew', 'client_signatory', 'company_signatory', 'scope'];

    public function materialSnapshot(): array
    {
        $out = [];
        foreach (self::MATERIAL_FIELDS as $f) {
            $v = $this->getAttribute($f);
            $out[$f] = $v instanceof Carbon ? $v->toDateString() : $v;
        }

        return $out;
    }

    /** Active contracts whose end date has passed read as expired until the scheduler marks them. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'active' && self::dateHasPassed($this->end_date)) {
            return 'expired';
        }

        return $this->status;
    }

    public function isLive(): bool
    {
        return $this->effectiveStatus() === 'active';
    }

    public function isClosed(): bool
    {
        return in_array($this->effectiveStatus(), config('company.contract_closed_statuses'));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()));
    }

    /** Date by which the client must give notice to stop the next cycle, if a notice period applies. */
    public function noticeDeadline(): ?Carbon
    {
        $next = $this->nextDueDate();
        if (! $next || ! $this->notice_period_days) {
            return null;
        }

        return $next->copy()->subDays($this->notice_period_days);
    }

    /**
     * The standard terms for this contract. Once signed, the copy frozen at signing is used so later
     * edits in Settings never change what the client agreed to. Before signing, the current terms apply.
     */
    public function standardTerms(): array
    {
        if ($this->standard_terms) {
            return $this->standard_terms;
        }

        return $this->renderStandardTerms();
    }

    /** Freeze the current terms onto the contract (called when it is signed). */
    public function freezeTerms(): void
    {
        $this->forceFill(['standard_terms' => $this->renderStandardTerms()])->save();
    }

    private function renderStandardTerms(): array
    {
        $notice = $this->notice_period_days ? $this->notice_period_days.' days\'' : '30 days\'';
        $cycle = $this->isRecurring()
            ? 'This agreement is billed '.strtolower($this->cycleName()).' at '.money($this->amount, $this->currency).' per '.$this->cycle()['per'].', invoiced in advance.'
            : 'This agreement is for a fixed price of '.money($this->amount, $this->currency).'.';
        $map = ['{deposit}' => (string) Setting::get('deposit_percent'), '{payment_days}' => (string) $this->payment_terms_days, '{late_fee}' => rtrim(rtrim(number_format((float) Setting::get('late_fee_monthly_percent'), 2), '0'), '.'), '{notice}' => $notice, '{company}' => config('company.name'), '{short}' => config('company.short'), '{cycle}' => $cycle];
        $out = [];
        foreach (Setting::get('contract_terms', []) as $heading => $text) {
            if ($heading === 'Recurring services' && ! $this->isRecurring()) {
                continue;
            }
            $out[$heading] = strtr($text, $map);
        }

        return $out;
    }

    public function companySignatoryName(): string
    {
        return $this->company_signatory ?: config('company.signatory');
    }

    public function clientSignatoryName(): string
    {
        return $this->client_signatory ?: $this->client->contact_name;
    }

    public function typeName(): string
    {
        return config("company.contract_types.{$this->type}", ucfirst($this->type));
    }

    public function statusName(): string
    {
        return config("company.contract_statuses.{$this->effectiveStatus()}", ucfirst($this->status));
    }

    public function cycle(): array
    {
        return config("company.billing_cycles.{$this->billing_cycle}", config('company.billing_cycles.one_time'));
    }

    public function cycleName(): string
    {
        return $this->cycle()['label'];
    }

    public function isRecurring(): bool
    {
        return $this->billing_cycle !== 'one_time' && $this->billing_cycle !== null;
    }

    /** "TT$600.00 / month" for recurring contracts, plain amount otherwise. */
    public function amountLabel(): string
    {
        $per = $this->cycle()['per'];

        return money($this->amount, $this->currency).($per ? ' / '.$per : '');
    }

    /** Number of billing cycles that have started between the start date and today (or the end date). */
    public function cyclesElapsed(): int
    {
        if (! $this->isRecurring()) {
            return 1;
        }
        $start = $this->start_date->copy()->startOfDay();
        $until = now()->startOfDay();
        if ($this->end_date && $this->end_date->lt($until)) {
            $until = $this->end_date->copy()->startOfDay();
        }
        if ($until->lt($start)) {
            return 0;
        }
        $cycle = $this->cycle();
        if ($cycle['days']) {
            return intdiv($start->diffInDays($until), $cycle['days']) + 1;
        }

        return intdiv((int) $start->diffInMonths($until), $cycle['months']) + 1;
    }

    /** Amount the client should have paid so far: the full price, or cycles elapsed times the cycle price. */
    public function dueToDate(): float
    {
        return $this->isRecurring() ? round($this->cyclesElapsed() * (float) $this->amount, 2) : (float) $this->amount;
    }

    /** When the next cycle starts, or null for one-off and finished contracts. */
    public function nextDueDate(): ?Carbon
    {
        if (! $this->isRecurring()) {
            return null;
        }
        $cycle = $this->cycle();
        $elapsed = $this->cyclesElapsed();
        $next = $cycle['days'] ? $this->start_date->copy()->addDays($elapsed * $cycle['days']) : $this->start_date->copy()->addMonthsNoOverflow($elapsed * $cycle['months']);
        if ($this->end_date && $next->gt($this->end_date)) {
            return null;
        }

        return $next;
    }

    /** Cycle price expressed per month, for the recurring-revenue stat. */
    public function monthlyEquivalent(): float
    {
        if (! $this->isRecurring()) {
            return 0;
        }
        $cycle = $this->cycle();

        return $cycle['days'] ? (float) $this->amount * (365 / 12) / $cycle['days'] : (float) $this->amount / $cycle['months'];
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', 'verified')->sum('amount');
    }

    public function pendingAmount(): float
    {
        return (float) $this->payments()->where('status', 'pending')->sum('amount');
    }

    /** What is owed right now: due to date minus verified payments. */
    public function balance(): float
    {
        return max(0, $this->dueToDate() - $this->paidAmount());
    }

    /** unpaid | partial | paid, from verified payments only. */
    public function paymentState(): string
    {
        $paid = $this->paidAmount();
        $due = $this->dueToDate();

        return match (true) {
            $due <= 0 => 'paid',
            $paid <= 0 => 'unpaid',
            $paid + 0.005 >= $due => 'paid',
            default => 'partial',
        };
    }

    public function isPaid(): bool
    {
        return $this->paymentState() === 'paid';
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** Signed in the portal (drawn signature) or marked signed by admin (uploaded copy). */
    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isSignedInPortal(): bool
    {
        return $this->signature_path !== null;
    }

    /** Clients can sign once the contract is sent for signature and not yet signed. */
    public function awaitingSignature(): bool
    {
        return ! $this->isSigned() && $this->sent_for_signature_at !== null && in_array($this->status, ['approved', 'active']);
    }

    /** Inline data URI so the signature prints inside the PDF without a second request. */
    public function signatureDataUri(): ?string
    {
        if (! $this->signature_path || ! Storage::disk('local')->exists($this->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($this->signature_path));
    }

    public function finance(): ContractFinance
    {
        return new ContractFinance($this);
    }
}
