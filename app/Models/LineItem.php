<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['description', 'quantity', 'unit_price', 'sort'])]
class LineItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2'];
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function total(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
