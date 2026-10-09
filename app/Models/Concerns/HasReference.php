<?php

namespace App\Models\Concerns;

use App\Support\Reference;
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

        return DB::transaction(fn () => parent::save($options));
    }
}
