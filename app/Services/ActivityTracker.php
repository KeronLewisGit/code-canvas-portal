<?php

namespace App\Services;

use App\Http\Controllers\Admin\ClientPreviewController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records sign-ins (with IP and device) and each client login's latest activity in plain words, e.g.
 * "Viewed invoice INV-000063" or "Accepted quotation Q-2026-004". Kept light: a repeat of the same activity within
 * a minute is not written again. An admin previewing the portal is never recorded as the client.
 */
class ActivityTracker
{
    public function signedIn(User $user, Request $request): void
    {
        DB::table('login_events')->insert(['user_id' => $user->id, 'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'created_at' => now()]);
        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now(), 'last_activity' => 'Signed in']);
    }

    public function record(Request $request): void
    {
        $user = $request->user();
        if (! $user?->isClient() || ClientPreviewController::active()) {
            return;
        }
        $activity = self::describe($request);
        if ($activity === null) {
            return;
        }
        $recentSame = $user->last_activity === $activity && $user->last_seen_at && $user->last_seen_at->gt(now()->subMinute());
        if (! $recentSame) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now(), 'last_activity' => Str::limit($activity, 160, '')]);
        }
    }

    /** What the request means in plain words, or null for requests not worth recording. */
    public static function describe(Request $request): ?string
    {
        $route = $request->route();
        if (! $route) {
            return null;
        }
        $model = fn (string $param) => is_object($route->parameter($param)) ? $route->parameter($param) : null;
        $ref = fn (string $param) => (string) ($model($param)?->getAttribute('reference') ?? '');

        return match ($route->getName()) {
            'portal.dashboard' => 'Viewed the overview',
            'portal.welcome' => 'Viewed the portal guide',
            'portal.welcome.complete' => 'Finished the welcome',
            'portal.quotations.index' => 'Viewed quotations',
            'portal.quotations.show' => 'Viewed quotation '.$ref('quotation'),
            'portal.quotations.answer' => ($request->input('answer') === 'accept' ? 'Accepted' : 'Declined').' quotation '.$ref('quotation'),
            'portal.contracts.index' => 'Viewed contracts',
            'portal.contracts.show' => 'Viewed contract '.$ref('contract'),
            'portal.contracts.sign' => 'Opened the signing page for '.$ref('contract'),
            'portal.contracts.sign.store' => 'Signed contract '.$ref('contract'),
            'portal.invoices.index' => 'Viewed invoices',
            'portal.invoices.show' => 'Viewed invoice '.$ref('invoice'),
            'portal.invoices.card' => 'Started a card payment for '.$ref('invoice'),
            'portal.payments.index' => 'Viewed payments',
            'portal.payments.create' => 'Opened the payment form',
            'portal.payments.store' => 'Submitted a payment of '.money((float) $request->input('amount')),
            'portal.payments.show' => 'Viewed a payment',
            'portal.receipts.index' => 'Viewed receipts',
            'documents.quotation' => 'Opened the PDF of quotation '.$ref('quotation'),
            'documents.invoice' => 'Opened the PDF of invoice '.$ref('invoice'),
            'documents.contract' => 'Opened the PDF of contract '.$ref('contract'),
            'documents.receipt' => 'Opened receipt '.($model('payment')?->getAttribute('receipt_number') ?? ''),
            'documents.statement' => 'Opened their statement of account',
            'files.proof', 'files.contract' => 'Downloaded a file',
            'account' => 'Viewed their account',
            'account.password' => 'Changed their password',
            'wipay.return' => 'Came back from a card payment',
            default => null,
        };
    }
}
