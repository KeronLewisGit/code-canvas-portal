<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-contract and portfolio financials, derived from the source-of-truth records:
 * invoices (what was billed) and verified payments (what was collected). Nothing here is stored.
 *
 * Definitions (also printed on reports):
 * - contracted value: one-off price, or cycle price x cycles in the term. Open-ended recurring contracts have no finite value.
 * - invoiced: total of invoices on the contract that are not draft or cancelled.
 * - collected: verified payments on the contract.
 * - receivable: invoiced minus collected, never below zero. Overdue is the part of that on invoices past their due date.
 * - remaining value: contracted value minus invoiced (what can still be billed).
 * - forecast: cycle starts in the next 12 months for live contracts, plus any un-invoiced remainder of live one-off contracts.
 *   Expired, terminated, cancelled and completed contracts contribute nothing to the forecast.
 * - cancellable: forecast revenue beyond the notice period on open-ended contracts, which the client could still stop.
 */
class ContractFinance
{
    /** @var array<int, array<string, array{amount: float, cancellable: float}>> keyed by months */
    private array $forecasts = [];

    public function __construct(private Contract $contract) {}

    public function cyclesInTerm(): ?int
    {
        $c = $this->contract;
        if (! $c->isRecurring()) {
            return 1;
        }
        if (! $c->end_date) {
            return null;
        }
        $cycle = $c->cycle();
        $start = $c->start_date->copy()->startOfDay();
        $end = $c->end_date->copy()->startOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        return $cycle['days'] ? intdiv($start->diffInDays($end), $cycle['days']) + 1 : intdiv((int) $start->diffInMonths($end), $cycle['months']) + 1;
    }

    /** Null for open-ended recurring contracts. */
    public function contractedValue(): ?float
    {
        $cycles = $this->cyclesInTerm();

        return $cycles === null ? null : round($cycles * (float) $this->contract->amount, 2);
    }

    public function invoiced(): float
    {
        return (float) $this->billableInvoices()->sum('total');
    }

    public function collected(): float
    {
        $c = $this->contract;
        if ($c->relationLoaded('payments')) {
            return (float) $c->payments->where('status', 'verified')->sum('amount');
        }

        return $c->paidAmount();
    }

    public function receivable(): float
    {
        return (float) $this->billableInvoices()->sum(fn (Invoice $i) => $i->balance());
    }

    public function overdue(): float
    {
        return (float) $this->billableInvoices()->filter(fn (Invoice $i) => $i->effectiveStatus() === 'overdue')->sum(fn (Invoice $i) => $i->balance());
    }

    public function remainingValue(): ?float
    {
        $value = $this->contractedValue();

        return $value === null ? null : max(0, round($value - $this->invoiced(), 2));
    }

    public function monthlyRecurring(): float
    {
        return $this->contract->isLive() ? $this->contract->monthlyEquivalent() : 0;
    }

    /**
     * Expected billings per month for the next $months months, keyed by Y-m.
     * Each entry: ['amount' => float, 'cancellable' => float].
     */
    public function forecast(int $months = 12, ?Carbon $from = null): array
    {
        if ($from === null && isset($this->forecasts[$months])) {
            return $this->forecasts[$months];
        }
        $result = $this->computeForecast($months, $from);
        if ($from === null) {
            $this->forecasts[$months] = $result;
        }

        return $result;
    }

    private function computeForecast(int $months, ?Carbon $from): array
    {
        $c = $this->contract;
        $from = ($from ?? now())->copy()->startOfDay();
        $horizon = $from->copy()->addMonthsNoOverflow($months)->endOfDay();
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $out[$from->copy()->addMonthsNoOverflow($i)->format('Y-m')] = ['amount' => 0.0, 'cancellable' => 0.0];
        }
        if (! $c->isLive()) {
            return $out;
        }

        if (! $c->isRecurring()) {
            $remaining = $this->remainingValue() ?? 0;
            if ($remaining > 0) {
                $month = ($c->end_date && $c->end_date->gt($from) ? $c->end_date : $from)->format('Y-m');
                if (isset($out[$month])) {
                    $out[$month]['amount'] += $remaining;
                }
            }

            return $out;
        }

        $cycle = $c->cycle();
        $noticeEdge = $c->end_date === null && $c->notice_period_days ? $from->copy()->addDays($c->notice_period_days) : null;
        $n = 0;
        while (true) {
            $date = $cycle['days'] ? $c->start_date->copy()->addDays($n * $cycle['days']) : $c->start_date->copy()->addMonthsNoOverflow($n * $cycle['months']);
            $n++;
            if ($date->gt($horizon) || ($c->end_date && $date->gt($c->end_date))) {
                break;
            }
            if ($date->lt($from)) {
                continue;
            }
            $key = $date->format('Y-m');
            if (! isset($out[$key])) {
                continue;
            }
            $out[$key]['amount'] += (float) $c->amount;
            if ($c->end_date === null && (! $noticeEdge || $date->gt($noticeEdge))) {
                $out[$key]['cancellable'] += (float) $c->amount;
            }
        }

        return $out;
    }

    public function summary(): array
    {
        $forecast = $this->forecast();

        return [
            'contracted_value' => $this->contractedValue(),
            'remaining_value' => $this->remainingValue(),
            'monthly_recurring' => $this->monthlyRecurring(),
            'invoiced' => $this->invoiced(),
            'collected' => $this->collected(),
            'receivable' => $this->receivable(),
            'overdue' => $this->overdue(),
            'forecast_12m' => array_sum(array_column($forecast, 'amount')),
            'cancellable_12m' => array_sum(array_column($forecast, 'cancellable')),
        ];
    }

    /** Portfolio totals across a set of contracts. Amounts are summed per currency. */
    public static function portfolio(Collection $contracts, int $months = 12): array
    {
        $totals = ['contracts' => $contracts->count(), 'live' => 0, 'contracted_value' => 0.0, 'open_ended' => 0, 'remaining_value' => 0.0, 'monthly_recurring' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0, 'receivable' => 0.0, 'overdue' => 0.0, 'forecast_12m' => 0.0, 'cancellable_12m' => 0.0];
        $months_out = [];
        foreach ($contracts as $contract) {
            $f = $contract->finance();
            $s = $f->summary();
            $totals['live'] += $contract->isLive() ? 1 : 0;
            if ($s['contracted_value'] === null) {
                $totals['open_ended']++;
            } else {
                $totals['contracted_value'] += $s['contracted_value'];
                $totals['remaining_value'] += $s['remaining_value'];
            }
            foreach (['monthly_recurring', 'invoiced', 'collected', 'receivable', 'overdue', 'forecast_12m', 'cancellable_12m'] as $k) {
                $totals[$k] += $s[$k];
            }
            foreach ($f->forecast($months) as $ym => $row) {
                $months_out[$ym]['amount'] = ($months_out[$ym]['amount'] ?? 0) + $row['amount'];
                $months_out[$ym]['cancellable'] = ($months_out[$ym]['cancellable'] ?? 0) + $row['cancellable'];
            }
        }
        ksort($months_out);
        $totals['forecast_by_month'] = $months_out;

        return $totals;
    }

    /** Invoices that count, from the eager-loaded relation when available. */
    private function billableInvoices(): Collection
    {
        $c = $this->contract;
        $invoices = $c->relationLoaded('invoices') ? $c->invoices : $c->invoices()->get();

        return $invoices->reject(fn (Invoice $i) => in_array($i->status, ['draft', 'cancelled']))->values();
    }
}
