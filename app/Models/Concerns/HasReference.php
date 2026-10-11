<?php

namespace App\Models\Concerns;

use App\Support\Reference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Assigns the document number (CT-2026-001, INV-000063, ...) on first save. The number is picked and
 * the row inserted inside one transaction so two simultaneous creates cannot take the same number.
 */
trait HasReference
{
    public static function bootHasReference(): void
    {
        static::creating(function ($model) {
            $model->reference ??= Reference::next(strtolower(class_basename(static::class)), static::class);
        });
    }

    public function save(array $options = []): bool
    {
        if ($this->exists || $this->reference) {
            return parent::save($options);
        }
        // Inside a caller's transaction a deadlock rolls back their work too, so only the caller can retry.
        if (DB::transactionLevel() > 0) {
            return parent::save($options);
        }
        // Two documents created at the same moment can deadlock on the number lock (MySQL gap locks on the first
        // number of a year) or, rarely, race to the same number: pick a fresh number and try again.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => parent::save($options));
            } catch (QueryException $e) {
                unset($this->reference); // a fresh number is picked on the next attempt
                $retryable = in_array($e->getCode(), ['40001', '23000', '23505'], true) || str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'UNIQUE');
                if (! $retryable || $attempt >= 3) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }
}
