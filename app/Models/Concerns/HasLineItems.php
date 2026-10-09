<?php

namespace App\Models\Concerns;

use App\Models\LineItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Shared by quotations and invoices: line items and totals. */
trait HasLineItems
{
    public static function bootHasLineItems(): void
    {
        static::deleting(fn ($model) => $model->items()->delete());
    }

    /** @return MorphMany<LineItem, $this> */
    public function items(): MorphMany
    {
        return $this->morphMany(LineItem::class, 'itemable')->orderBy('sort');
    }

    /** Replace all line items from form input and recompute totals. */
    public function syncItems(array $rows): void
    {
        $this->items()->delete();
        $sort = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['description'] ?? '')) === '') {
                continue;
            }
            $this->items()->create([
                'description' => trim($row['description']),
                'quantity' => (float) ($row['quantity'] ?? 1),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'sort' => $sort++,
            ]);
        }
        $this->recalculate();
    }

    public function recalculate(): void
    {
        $subtotal = round($this->items()->get()->sum(fn (LineItem $i) => $i->total()), 2);
        $tax = round($subtotal * ((float) $this->tax_rate / 100), 2);
        $this->forceFill(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => round($subtotal + $tax, 2)])->save();
    }
}
