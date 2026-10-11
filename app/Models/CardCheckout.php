<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $order_id
 * @property int $client_id
 * @property int $invoice_id
 * @property int|null $user_id
 * @property string $amount
 * @property string $currency
 * @property string $status started | succeeded | failed
 * @property string|null $transaction_id
 * @property int|null $payment_id
 * @property string|null $message
 * @property-read Invoice $invoice
 * @property-read Client $client
 */
#[Fillable(['order_id', 'client_id', 'invoice_id', 'user_id', 'amount', 'currency', 'status', 'transaction_id', 'payment_id', 'message'])]
class CardCheckout extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
