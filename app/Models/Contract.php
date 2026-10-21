<?php

namespace App\Models;

use App\Models\Concerns\DateRules;
use App\Models\Concerns\HasReference;
use App\Services\ContractFinance;
use App\Support\LedgerCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $reference
 * @property int $client_id
 * @property string $title
 * @property string $type
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string $amount
 * @property string $billing_cycle
 * @property bool $bill_in_arrears recurring only: invoice each period on its last day instead of its first
 * @property string $currency
 * @property string $status
 * @property string|null $scope
 * @property string|null $terms special terms agreed for this client; printed before the standard terms and prevail over them
 * @property string|null $file_path
 * @property array<string, string>|null $standard_terms
 * @property Carbon|null $signed_at
 * @property string|null $signature_path
 * @property string|null $signed_name
 * @property string|null $signed_ip
 * @property int|null $signed_by
 * @property Carbon|null $sent_for_signature_at
 * @property int $payment_terms_days
 * @property Carbon|null $archived_at
 * @property string|null $deposit_percent override of the Settings deposit (null = use the setting)
 * @property int|null $notice_period_days
 * @property bool $auto_renew
 * @property int|null $renewal_of_id
 * @property string|null $client_signatory
 * @property string|null $company_signatory
 * @property bool $confidential
 * @property int $version
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $terminated_at
 * @property string|null $termination_reason
 * @property Carbon|null $reminded_at
 * @property-read Client $client
 */
#[Fillable(['reference', 'client_id', 'title', 'type', 'start_date', 'end_date', 'amount', 'billing_cycle', 'bill_in_arrears', 'currency', 'status', 'scope', 'terms', 'file_path', 'standard_terms', 'signed_at', 'signature_path', 'signed_name', 'signed_ip', 'signed_by', 'sent_for_signature_at', 'payment_terms_days', 'notice_period_days', 'auto_renew', 'renewal_of_id', 'client_signatory', 'company_signatory', 'confidential', 'version', 'approved_at', 'approved_by', 'activated_at', 'terminated_at', 'termination_reason', 'reminded_at', 'deposit_percent', 'archived_at'])]
class Contract extends Model
{
    use DateRules, HasReference;

    /** Statuses a client may submit a payment against (a deposit on an approved contract, or a balance later). */
    public const PAYABLE_STATUSES = ['approved', 'active', 'completed', 'expired'];

    /** Mirror the database defaults so a freshly created model behaves like a reloaded one. */
    protected $attributes = ['status' => 'draft', 'billing_cycle' => 'one_time', 'bill_in_arrears' => false, 'currency' => 'TTD', 'payment_terms_days' => 14, 'auto_renew' => false, 'confidential' => false, 'version' => 1];

    protected static function booted(): void
    {
        static::saved(fn () => LedgerCache::flush());
        static::deleted(fn () => LedgerCache::flush());
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'start_date' => 'date', 'end_date' => 'date', 'signed_at' => 'datetime', 'sent_for_signature_at' => 'datetime', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'terminated_at' => 'datetime', 'reminded_at' => 'datetime', 'auto_renew' => 'boolean', 'bill_in_arrears' => 'boolean', 'confidential' => 'boolean', 'standard_terms' => 'array', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issue_date');
    }

    /** @return HasMany<Quotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on');
    }

    /** @return HasMany<ContractVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    /** @return MorphMany<ActivityLog, $this> */
    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at');
    }

    /** @return BelongsTo<Contract, $this> */
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'renewal_of_id');
    }

    /** @return HasMany<Contract, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(Contract::class, 'renewal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Material terms that are versioned on amendment. */
    public const MATERIAL_FIELDS = ['title', 'type', 'start_date', 'end_date', 'amount', 'billing_cycle', 'bill_in_arrears', 'currency', 'payment_terms_days', 'notice_period_days', 'auto_renew', 'client_signatory', 'company_signatory', 'scope', 'terms'];

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

    /** Statuses that mean the contract was in effect, so the client always keeps it on record. */
    public const IN_EFFECT_STATUSES = ['active', 'completed', 'expired', 'terminated'];

    /**
     * What the client sees: contracts that took effect, plus approved or cancelled ones only if they were actually
     * sent to the client (or signed). Drafts, and contracts approved internally but never sent, stay hidden.
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('status', self::IN_EFFECT_STATUSES)
            ->orWhere(fn ($w) => $w->whereIn('status', ['approved', 'cancelled'])->where(fn ($x) => $x->whereNotNull('sent_for_signature_at')->orWhereNotNull('signed_at'))))
            // An archived contract the client never signed is withdrawn from their portal; signed ones stay on record.
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereNotNull('signed_at'));
    }

    public function isVisibleToClient(): bool
    {
        $issued = in_array($this->status, self::IN_EFFECT_STATUSES) || (in_array($this->status, ['approved', 'cancelled']) && ($this->sent_for_signature_at !== null || $this->signed_at !== null));

        return $issued && ($this->archived_at === null || $this->signed_at !== null);
    }

    /** @param  Builder<Contract>  $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Waiting for the client's signature for longer than config('company.signatures.overdue_after_days'). */
    public function signatureOverdue(): bool
    {
        return $this->awaitingSignature() && $this->sent_for_signature_at->lte(now()->subDays((int) config('company.signatures.overdue_after_days', 30)));
    }

    /** Closed contracts can be archived as they are; unsigned draft/approved ones are withdrawn (cancelled) first. */
    public function canBeArchived(): bool
    {
        return ! $this->isArchived() && (in_array($this->status, config('company.contract_closed_statuses')) || (in_array($this->status, ['draft', 'approved']) && ! $this->isSigned()));
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
        // Billed in arrears, the next due date is the end of the period; the next cycle starts the day after.
        if ($this->billsInArrears()) {
            $next = $next->copy()->addDay();
        }

        return $next->copy()->subDays($this->notice_period_days);
    }

    /**
     * The standard terms for this contract. Once signed, the copy frozen at signing is used so later
     * edits in Settings never change what the client agreed to. Before signing, the current terms apply.
     */
    public function standardTerms(): array
    {
        if ($this->standard_terms !== null) {
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
            ? 'This agreement is billed '.strtolower($this->cycleName()).' at '.money($this->amount, $this->currency).' per '.$this->cycle()['per'].', invoiced '.($this->billsInArrears() ? 'at the end of each '.$this->cycle()['per'] : 'in advance').'.'
            : 'This agreement is for a fixed price of '.money($this->amount, $this->currency).'.';
        $map = ['{deposit}' => (string) Setting::get('deposit_percent'), '{payment_days}' => (string) $this->payment_terms_days, '{late_fee}' => rtrim(rtrim(number_format((float) Setting::get('late_fee_monthly_percent'), 2), '0'), '.'), '{notice}' => $notice, '{company}' => config('company.name'), '{short}' => config('company.short'), '{cycle}' => $cycle];
        $out = [];
        foreach (Setting::get('contract_terms', []) as $heading => $text) {
            if ($heading === 'Recurring services' && ! $this->isRecurring()) {
                continue;
            }
            $text = strtr($text, $map);
            $out[$heading] = $this->billsInArrears() ? str_replace('invoiced in advance at the start of the cycle', 'invoiced at the end of the cycle', $text) : $text;
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
        $cycle = config("company.billing_cycles.{$this->billing_cycle}");
        if (! is_array($cycle)) {
            // Falling back to one_time here would make the forecast loop spin forever and the cycle maths divide by zero.
            throw new \UnexpectedValueException("Unknown billing cycle [{$this->billing_cycle}] on contract {$this->reference}.");
        }

        return $cycle;
    }

    public function cycleName(): string
    {
        return $this->cycle()['label'];
    }

    public function isRecurring(): bool
    {
        return $this->billing_cycle !== 'one_time';
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
        // Count the cycle starts on or before $until. Months step with addMonthsNoOverflow (31 Jan → 28 Feb → 31 Mar),
        // the same rule nextDueDate() and the forecast use, so a cycle is counted on the day it is shown as due.
        $n = 1;
        while ($n < 10000 && $this->cycleStart($n)->lte($until)) {
            $n++;
        }

        return $n;
    }

    /** Start date of cycle $n (0 = the contract start). */
    public function cycleStart(int $n): Carbon
    {
        $cycle = $this->cycle();

        return $cycle['days'] ? $this->start_date->copy()->startOfDay()->addDays($n * $cycle['days']) : $this->start_date->copy()->startOfDay()->addMonthsNoOverflow($n * $cycle['months']);
    }

    /** Recurring contract invoiced on the last day of each billing period, not the first. */
    public function billsInArrears(): bool
    {
        return $this->bill_in_arrears && $this->isRecurring();
    }

    /** Last day of billing period $n, cut short by the end date. When billed in arrears this is the day it is invoiced. */
    public function periodEnd(int $n): Carbon
    {
        $end = $this->cycleStart($n + 1)->subDay();

        return $this->end_date && $end->gt($this->end_date) ? $this->end_date->copy()->startOfDay() : $end;
    }

    /** Billing periods that are owed by now: those that have started, or, billed in arrears, those that have ended. */
    public function cyclesDue(): int
    {
        if (! $this->billsInArrears()) {
            return $this->cyclesElapsed();
        }
        $today = now()->startOfDay();
        $n = 0;
        while ($n < 10000 && (! $this->end_date || $this->cycleStart($n)->lte($this->end_date)) && $this->periodEnd($n)->lte($today)) {
            $n++;
        }

        return $n;
    }

    /** Amount the client should have paid so far: the full price, or periods owed times the period price. */
    public function dueToDate(): float
    {
        return $this->isRecurring() ? round($this->cyclesDue() * (float) $this->amount, 2) : (float) $this->amount;
    }

    /** When the next period is invoiced (its first day, or its last day when billed in arrears), or null for one-off and finished contracts. */
    public function nextDueDate(): ?Carbon
    {
        if (! $this->isRecurring()) {
            return null;
        }
        $due = $this->cyclesDue();
        $start = $this->cycleStart($due);
        if ($this->end_date && $start->gt($this->end_date)) {
            return null;
        }

        return $this->billsInArrears() ? $this->periodEnd($due) : $start;
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
        return LedgerCache::remember('contract-paid:'.$this->id, fn () => (float) $this->payments()->where('status', 'verified')->sum('amount'));
    }

    /**
     * Verified payments made against the contract without naming an invoice are applied to its open invoices
     * (same currency, oldest first) after each invoice's own payments. So paying "the contract" also settles the
     * invoice that bills it, and contract and invoice balances agree. Returns invoice id => amount applied.
     *
     * @return array<int, float>
     */
    public function creditAllocation(): array
    {
        return $this->allocateCredit()['applied'];
    }

    /** Contract-level payments not needed by any invoice yet: shown as credit, used by the next invoice. */
    public function unallocatedCredit(): float
    {
        return $this->allocateCredit()['left'];
    }

    /** @return array{applied: array<int, float>, left: float} */
    private function allocateCredit(): array
    {
        return LedgerCache::remember('contract-credit:'.$this->id, fn () => $this->computeCreditAllocation());
    }

    /** @return array{applied: array<int, float>, left: float} */
    private function computeCreditAllocation(): array
    {
        $pool = (float) $this->payments()->where('status', 'verified')->whereNull('invoice_id')->sum('amount');
        $applied = [];
        if ($pool > 0) {
            $invoices = $this->invoices()->whereNotIn('status', ['draft', 'cancelled'])->where('currency', $this->currency)->reorder()->orderBy('issue_date')->orderBy('id')->get();
            foreach ($invoices as $invoice) {
                $take = round(min(max(0, (float) $invoice->total - $invoice->directPaidAmount()), $pool), 2);
                if ($take > 0) {
                    $applied[$invoice->id] = $take;
                    $pool = round($pool - $take, 2);
                }
                if ($pool <= 0) {
                    break;
                }
            }
        }

        return ['applied' => $applied, 'left' => max(0, round($pool, 2))];
    }

    /** What this contract still owes that no open invoice covers yet (balance minus open invoice balances). */
    public static function notYetInvoiced(Contract $contract): float
    {
        $onInvoices = $contract->invoices()->whereIn('status', ['sent', 'partial'])->get()->sum(fn (Invoice $i) => $i->balance());

        return max(0, round($contract->balance() - $onInvoices, 2));
    }

    /** Re-derive the status of every invoice on this contract (after any payment on it changes). */
    public function refreshInvoiceStatuses(): void
    {
        $this->invoices()->whereNotIn('status', ['draft', 'cancelled'])->get()->each->refreshPaymentStatus();
    }

    public function pendingAmount(): float
    {
        return LedgerCache::remember('contract-pending:'.$this->id, fn () => (float) $this->payments()->where('status', 'pending')->sum('amount'));
    }

    /** What is owed right now: due to date minus verified payments. */
    public function balance(): float
    {
        return max(0, $this->dueToDate() - $this->paidAmount());
    }

    /** not_due | unpaid | partial | paid, from verified payments only. */
    public function paymentState(): string
    {
        $paid = $this->paidAmount();
        $due = $this->dueToDate();

        return match (true) {
            $due <= 0 => $paid > 0 ? 'paid' : 'not_due', // e.g. a recurring contract before its first cycle
            $paid <= 0 => 'unpaid',
            $paid + 0.005 >= $due => 'paid',
            default => 'partial',
        };
    }

    /** @return BelongsTo<User, $this> */
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

    /**
     * Whether the client has actually been given this contract: it is in effect (or was), or it has been sent
     * to them for signature. An approved contract that was never sent does not count yet.
     */
    public function isIssued(): bool
    {
        return in_array($this->status, ['active', 'completed', 'expired']) || ($this->status === 'approved' && ($this->sent_for_signature_at !== null || $this->signed_at !== null));
    }

    public function acceptsPayments(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES) && $this->isIssued() && $this->balance() > 0;
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

    private ?ContractFinance $financeCache = null;

    /** One finance calculator per loaded contract, so reports reuse its summary instead of recomputing it. */
    public function finance(): ContractFinance
    {
        return $this->financeCache ??= new ContractFinance($this);
    }
}
