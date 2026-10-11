<?php

namespace App\Support;

/**
 * Per-request memo for payment totals (verified/pending sums and contract credit allocation), which pages ask for
 * many times over. Cleared whenever a payment, invoice or contract is saved or deleted, and at the start of every
 * request, so a figure is never stale: it only saves repeating the same query within one page.
 */
class LedgerCache
{
    /** @var array<string, mixed> */
    private static array $values = [];

    public static function remember(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, self::$values)) {
            self::$values[$key] = $compute();
        }

        return self::$values[$key];
    }

    public static function flush(): void
    {
        self::$values = [];
    }
}
