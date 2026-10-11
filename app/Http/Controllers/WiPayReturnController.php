<?php

namespace App\Http\Controllers;

use App\Services\WiPay;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where WiPay sends its result: the client's browser comes back here (GET), and WiPay may also post it server to
 * server. Public on purpose (a session may have expired during checkout); nothing is recorded unless the result's
 * hash proves it came from WiPay and it matches a checkout we started.
 */
class WiPayReturnController extends Controller
{
    public function __invoke(Request $request, WiPay $wipay): Response
    {
        $checkout = $wipay->complete($request->all());

        // WiPay's own server-to-server notification only needs an acknowledgement.
        if ($request->isMethod('post') && ! $request->user() && ! $request->acceptsHtml()) {
            return response('OK');
        }
        $user = $request->user();
        $own = $checkout && $user?->isClient() && $user->client_id === $checkout->client_id;
        $target = $own ? route('portal.invoices.show', $checkout->invoice_id) : ($user ? route('home') : route('login'));

        return match (true) {
            $checkout?->status === 'succeeded' => redirect($target)->with($user ? 'saved' : 'status', 'Thank you. Your card payment of '.money($checkout->amount, $checkout->currency).' went through and your invoice is updated.'),
            $checkout?->status === 'failed' => redirect($target)->with($user ? 'warning' : 'status', 'The card payment was not completed'.($checkout->message ? ': '.$checkout->message : '.').' You have not been charged. You can try again or pay by bank transfer.'),
            default => redirect($target)->with($user ? 'warning' : 'status', 'We could not confirm that card payment. If you were charged, contact '.config('company.email').' with your WiPay receipt.'),
        };
    }
}
