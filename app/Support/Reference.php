<?php

namespace App\Support;

/**
 * Document numbers from config('company.numbering'): CT-2026-001, Q-2026-001, INV-000063.
 * {seq:N} is the next running number for that pattern (per year when {year} is present), never lower than 'start'.
 */
class Reference
{
    public static function next(string $type, string $model): string
    {
        $cfg = config("company.numbering.{$type}");
        if (! $cfg) {
            // Legacy call style: next('CT', Contract::class)
            $cfg = ['pattern' => $type.'-{year}-{seq:3}', 'start' => 1];
        }
        $pattern = str_replace('{year}', now()->format('Y'), $cfg['pattern']);
        preg_match('/\{seq:(\d+)\}/', $pattern, $m);
        $pad = (int) ($m[1] ?? 3);
        [$prefix, $suffix] = explode($m[0], $pattern, 2);

        $regex = '/^'.preg_quote($prefix, '/').'(\d{'.$pad.'})'.preg_quote($suffix, '/').'$/';
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
