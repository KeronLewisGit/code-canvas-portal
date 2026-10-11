<?php

namespace App\Services;

use App\Models\CardCheckout;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Card payments through WiPay's hosted checkout.
 *
 * 1. start(): we ask WiPay for a checkout page for the invoice's balance and send the client there.
 * 2. WiPay sends the client back to /payments/wipay/return with the result (and may also post it server to server).
 * 3. complete(): the result is trusted only if its hash (md5 of transaction id + total + our API key) checks out and
 *    it matches a checkout we started for that amount and currency. It is then recorded once, as a verified payment.
 */
class WiPay
{
    public static function enabled(): bool
    {
        return (bool) config('company.wipay.enabled') && filled(config('company.wipay.api_key')) && filled(config('company.wipay.account_number'));
    }

    /** What a client can pay by card on this invoice now (its balance less payments waiting to be verified), or null. */
    public static function payableAmount(Invoice $invoice): ?float
    {
        if (! self::enabled() || ! $invoice->acceptsPayments() || ! in_array($invoice->currency, config('company.wipay.currencies'), true)) {
            return null;
        }
        $owed = round($invoice->balance() - (float) $invoice->payments()->where('status', 'pending')->sum('amount'), 2);

        return $owed > 0 ? $owed : null;
    }

    /** Creates the checkout and returns WiPay's page to send the client to. */
    public function start(Invoice $invoice, User $user): string
    {
        $amount = self::payableAmount($invoice) ?? throw new RuntimeException('Nothing on this invoice can be paid by card right now.');
        $checkout = CardCheckout::create([
            'order_id' => $invoice->reference.'-'.Str::upper(Str::random(8)),
            'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'user_id' => $user->id,
            'amount' => $amount, 'currency' => $invoice->currency, 'status' => 'started',
        ]);
        $cfg = config('company.wipay');
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout($cfg['timeout'])->post($cfg['request_url'], array_filter([
            'account_number' => $cfg['account_number'],
            'avs' => '0',
            'country_code' => $cfg['country_code'],
            'currency' => $invoice->currency,
            'data' => json_encode(['order_id' => $checkout->order_id, 'invoice' => $invoice->reference]),
            'environment' => $cfg['environment'],
            'fee_structure' => $cfg['fee_structure'],
            'method' => 'credit_card',
            'order_id' => $checkout->order_id,
            'origin' => Str::slug(config('company.short')).'-portal', // WiPay allows only letters, numbers, dashes, underscores
            'response_url' => route('wipay.return'),
            'total' => number_format($amount, 2, '.', ''),
            'name' => $user->name,
            'email' => $user->email,
        ], fn ($v) => $v !== null && $v !== ''));

        $url = $response->json('url');
        if ($response->failed() || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            $checkout->update(['status' => 'failed', 'message' => 'WiPay did not return a checkout page (HTTP '.$response->status().').']);
            Log::warning('WiPay checkout could not be started', ['order_id' => $checkout->order_id, 'status' => $response->status(), 'body' => Str::limit($response->body(), 500)]);
            throw new RuntimeException('The card payment page could not be opened just now. Please try again in a few minutes, or pay by bank transfer.');
        }

        return $url;
    }

    /**
     * Records WiPay's result. Returns the checkout (with its final status), or null when the result could not be
     * matched or verified (nothing is recorded then). Safe to receive the same result more than once.
     *
     * @param  array<string, mixed>  $result
     */
    public function complete(array $result): ?CardCheckout
    {
        $orderId = (string) ($result['order_id'] ?? '');
        $checkout = $orderId !== '' ? CardCheckout::firstWhere('order_id', $orderId) : null;
        if (! $checkout) {
            Log::warning('WiPay result for an unknown order', ['order_id' => $orderId]);

            return null;
        }
        $status = strtolower((string) ($result['status'] ?? ''));
        if ($status !== 'success') {
            if ($checkout->status === 'started') {
                $checkout->update(['status' => 'failed', 'message' => Str::limit((string) ($result['message'] ?? 'The payment was not completed.'), 250)]);
            }

            return $checkout;
        }
        $transactionId = (string) ($result['transaction_id'] ?? '');
        $total = (string) ($result['total'] ?? '');
        if (! self::hashMatches($transactionId, $total, (string) ($result['hash'] ?? ''))) {
            Log::warning('WiPay result failed hash verification', ['order_id' => $orderId, 'transaction_id' => $transactionId]);

            return null;
        }
        // The amount charged must cover what we asked for (it is higher when the client pays the card fee).
        $currencyOk = empty($result['currency']) || strtoupper((string) $result['currency']) === $checkout->currency;
        if (! $currencyOk || (float) $total + 0.005 < (float) $checkout->amount) {
            Log::warning('WiPay result does not match its checkout', ['order_id' => $orderId, 'total' => $total, 'expected' => $checkout->amount]);

            return null;
        }

        return DB::transaction(function () use ($checkout, $transactionId) {
            $locked = CardCheckout::lockForUpdate()->findOrFail($checkout->id);
            if ($locked->status === 'succeeded') {
                return $locked; // already recorded (the browser return and WiPay's own notification can both arrive)
            }
            $invoice = $locked->invoice;
            $payment = Payment::create([
                'client_id' => $locked->client_id, 'contract_id' => $invoice->contract_id, 'invoice_id' => $invoice->id,
                'amount' => $locked->amount, 'currency' => $locked->currency, 'method' => 'card', 'paid_on' => now(),
                'reference' => 'WiPay '.$transactionId, 'note' => 'Paid online by card. Order '.$locked->order_id.'.',
                'status' => 'verified', 'submitted_by' => $locked->user_id, 'verified_at' => now(),
            ]);
            $payment->refreshDocuments();
            $locked->update(['status' => 'succeeded', 'transaction_id' => $transactionId, 'payment_id' => $payment->id, 'message' => null]);
            Audit::log($payment, 'card_payment', ['order_id' => $locked->order_id, 'transaction_id' => $transactionId]);
            app(Notifier::class)->cardPaymentReceived($payment->load('client.users', 'invoice', 'contract'));

            return $locked;
        });
    }

    /** WiPay's proof that a result is genuine: md5(transaction_id . total . api_key), total as sent or to 2 decimals. */
    public static function hashMatches(string $transactionId, string $total, string $hash): bool
    {
        if ($transactionId === '' || $total === '' || $hash === '') {
            return false;
        }
        $key = (string) config('company.wipay.api_key');
        $candidates = array_unique([$total, is_numeric($total) ? number_format((float) $total, 2, '.', '') : $total]);
        foreach ($candidates as $candidate) {
            if (hash_equals(md5($transactionId.$candidate.$key), strtolower($hash))) {
                return true;
            }
        }

        return false;
    }
}
