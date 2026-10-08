<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/** One definition of "has this date passed": a date is current through the end of its own day. */
trait DateRules
{
    public static function dateHasPassed(?Carbon $date): bool
    {
        return $date !== null && $date->copy()->endOfDay()->isPast();
    }
}
