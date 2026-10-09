<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Document numbers from config('company.numbering'): CT-2026-001, Q-2026-001, INV-000063.
 * {seq:N} is the next running number for that pattern (per year when {year} is present), never lower than 'start'.
 * Call inside a transaction (see HasReference) so the row lock holds until the new row is inserted.
 */
class Reference
{
    public static function next(string $type, string $model): string
    {
        $cfg = config("company.numbering.{$type}");
        if (! is_array($cfg) || empty($cfg['pattern'])) {
            throw new InvalidArgumentException("No numbering pattern configured for [{$type}] in config/company.php.");
        }
        $pattern = str_replace('{year}', now()->format('Y'), $cfg['pattern']);
        if (! preg_match('/\{seq:(\d+)\}/', $pattern, $m)) {
            throw new InvalidArgumentException("Numbering pattern for [{$type}] has no {seq:N} placeholder.");
        }
        $pad = (int) $m[1];
        [$prefix, $suffix] = explode($m[0], $pattern, 2);

        // Accept numbers longer than the padding so the sequence keeps counting past 999 / 999999.
        $regex = '/^'.preg_quote($prefix, '/').'(\d{'.$pad.',})'.preg_quote($suffix, '/').'$/';
        $max = 0;
        foreach ($model::query()->where('reference', 'like', $prefix.'%')->lockForUpdate()->pluck('reference') as $ref) {
            if (preg_match($regex, $ref, $mm)) {
                $max = max($max, (int) $mm[1]);
            }
        }
        $n = max((int) ($cfg['start'] ?? 1), $max + 1);

        return $prefix.str_pad((string) $n, $pad, '0', STR_PAD_LEFT).$suffix;
    }
}
