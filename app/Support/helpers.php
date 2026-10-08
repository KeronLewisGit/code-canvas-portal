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
