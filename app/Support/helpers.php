<?php

if (! function_exists('money')) {
    /** Format an amount with the currency symbol, e.g. TT$1,250.00. No intl extension needed. */
    function money(float|int|string|null $amount, ?string $currency = null): string
    {
        $currency ??= config('company.currency.code');
        $symbol = config("company.currencies.{$currency}", $currency.' ');

        return $symbol.number_format((float) $amount, 2);
    }
}

if (! function_exists('status_label')) {
    /** The display name for any status key (contract, quotation, invoice, payment or payment state), e.g. not_due → "Nothing due yet". */
    function status_label(string $status): string
    {
        $labels = config('company.contract_statuses') + config('company.quotation_statuses') + config('company.invoice_statuses') + config('company.payment_statuses')
            + ['unpaid' => 'Unpaid', 'not_due' => 'Nothing due yet', 'signed' => 'Signed'];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (! function_exists('money_by_currency')) {
    /**
     * Totals that may span currencies, shown side by side instead of being added together:
     * ['TTD' => 1250, 'USD' => 300] → "TT$1,250.00 · US$300.00". Zero totals are dropped; nothing at all shows 0.00.
     *
     * @param  array<string, float|int>  $totals
     */
    function money_by_currency(array $totals): string
    {
        $parts = collect($totals)->filter(fn ($v) => round((float) $v, 2) != 0)->map(fn ($v, $code) => money($v, $code));

        return $parts->isEmpty() ? money(0) : $parts->implode(' · ');
    }
}

if (! function_exists('sum_by_currency')) {
    /**
     * Sum a value per currency over records that have a `currency` attribute.
     *
     * @return array<string, float>
     */
    function sum_by_currency(iterable $records, callable $value): array
    {
        $totals = [];
        foreach ($records as $record) {
            $code = $record->currency ?? config('company.currency.code');
            $totals[$code] = round(($totals[$code] ?? 0) + (float) $value($record), 2);
        }

        return $totals;
    }
}
