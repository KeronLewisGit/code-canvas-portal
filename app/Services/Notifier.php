<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Every email the portal sends. Client messages go to the client's portal logins (or the client's contact
 * email if no login exists yet); admin messages go to every admin user. Reminders are logged and stamped.
 */
class Notifier
{
    public function quotationSent(Quotation $q): void
    {
        $this->client($q->client, new PortalMail('Quotation '.$q->reference.' is ready for you', ['We have prepared a quotation for "'.$q->title.'" totalling '.money($q->total, $q->currency).'.', $q->valid_until ? 'It is valid until '.$q->valid_until->format('j F Y').'.' : 'Let us know if you have any questions.', 'You can review it, download a PDF, and accept or decline it in your client portal.'], 'Review the quotation', route('portal.quotations.show', $q)));
        $this->adminActed() || $this->admins(new PortalMail('Sent: quotation '.$q->reference, [$q->reference.' for '.$q->client->company.' ('.money($q->total, $q->currency).') was emailed to the client.'], 'Open quotation', route('admin.quotations.show', $q)));
    }

    /**
     * When acceptance sent the contract out for signature, the client's contract email already thanks them,
     * so they get one email rather than two.
     */
    public function quotationAnswered(Quotation $q, bool $accepted, bool $notifyAdmins = true, ?Contract $contract = null, bool $contractSent = false): void
    {
        $next = match (true) {
            ! $accepted => 'You may want to follow up with the client.',
            $contractSent => 'Contract '.$contract->reference.' was generated from it and sent to the client to sign.',
            $contract !== null => 'Contract '.$contract->reference.' was drafted from it. Review it and send it for signature.',
            $q->contract_id !== null => 'It is linked to an existing contract, so no new contract was created.',
            default => 'Next: create the contract from the quotation page.',
        };
        $notifyAdmins && $this->admins(new PortalMail(($accepted ? 'Accepted' : 'Declined').': quotation '.$q->reference, [$q->client->company.' has '.($accepted ? 'accepted' : 'declined').' '.$q->reference.' ("'.$q->title.'", '.money($q->total, $q->currency).').', $next], $contract ? 'Open contract' : 'Open quotation', $contract ? route('admin.contracts.show', $contract) : route('admin.quotations.show', $q)));
        if ($contractSent) {
            return;
        }
        $this->client($q->client, new PortalMail('Thank you: quotation '.$q->reference.' '.($accepted ? 'accepted' : 'declined'), [$accepted ? 'We have received your acceptance of '.$q->reference.'. We are preparing your contract and will email you as soon as it is ready to sign.' : 'We have noted that '.$q->reference.' was declined. If a revised quote would help, reply to this email.'], 'View in your portal', route('portal.quotations.show', $q)));
    }

    public function invoiceSent(Invoice $i): void
    {
        $this->client($i->client, new PortalMail('Invoice '.$i->reference.' for '.money($i->total, $i->currency), ['Invoice '.$i->reference.' ("'.$i->title.'") is now available.', 'Amount due: '.money($i->balance(), $i->currency).($i->due_date ? ', payable by '.$i->due_date->format('j F Y') : '').' ('.$i->termsLabel().').', 'Pay by bank transfer, cheque or cash, then submit the payment in your portal so we can confirm it.'], 'View and pay the invoice', route('portal.invoices.show', $i)));
        $this->adminActed() || $this->admins(new PortalMail('Sent: invoice '.$i->reference, [$i->reference.' for '.$i->client->company.' ('.money($i->total, $i->currency).') was emailed to the client.'], 'Open invoice', route('admin.invoices.show', $i)));
    }

    public function paymentSubmitted(Payment $p): void
    {
        $this->admins(new PortalMail('Payment to verify: '.money($p->amount, $p->currency).' from '.$p->client->company, [$p->client->company.' submitted a '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').($p->invoice ? ' against '.$p->invoice->reference : '').'.', $p->proof_path ? 'A proof file is attached in the portal.' : 'Reference: '.($p->reference ?: 'none given').'.', 'Please check your records and confirm or reject it.'], 'Review the payment', route('admin.payments.show', $p)));
        $this->client($p->client, new PortalMail('We received your payment of '.money($p->amount, $p->currency), ['Thank you. Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' has been submitted and is awaiting verification.', 'We usually confirm within one working day and will email you once it is verified.'], 'View payment', route('portal.payments.show', $p)));
    }

    public function paymentDecided(Payment $p): void
    {
        $ok = $p->status === 'verified';
        $this->client($p->client, new PortalMail($ok ? 'Payment confirmed: '.money($p->amount, $p->currency) : 'We could not verify your payment', $ok
            ? ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' has been verified. Thank you.', $p->invoice ? 'Invoice '.$p->invoice->reference.' balance is now '.money($p->invoice->balance(), $p->invoice->currency).'.' : ($p->contract ? 'Contract '.$p->contract->reference.' balance is now '.money($p->contract->balance(), $p->contract->currency).'.' : 'Your balance has been updated.')]
            : ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' could not be verified.', 'Reason: '.$p->rejection_reason, 'Please check the details and submit it again, or reply to this email if you think this is a mistake.'],
            $ok ? 'View payment' : 'Submit again', $ok ? route('portal.payments.show', $p) : route('portal.payments.create', array_filter(['invoice' => $p->invoice_id, 'contract' => $p->contract_id]))));
    }

    public function creditNoteIssued(Payment $credit): void
    {
        $i = $credit->invoice;
        $this->client($credit->client, new PortalMail('Credit of '.money($credit->amount, $credit->currency).' applied to invoice '.$i->reference, [
            'We have applied a credit of '.money($credit->amount, $credit->currency).' to invoice '.$i->reference.' ("'.$i->title.'").',
            'Reason: '.$credit->note,
            $i->balance() > 0 ? 'The balance is now '.money($i->balance(), $i->currency).'.' : 'Nothing is left to pay on this invoice.',
        ], 'View the invoice', route('portal.invoices.show', $i)));
    }

    public function refundIssued(Payment $refund): void
    {
        $this->client($refund->client, new PortalMail('Refund of '.money(abs((float) $refund->amount), $refund->currency), [
            'We have refunded '.money(abs((float) $refund->amount), $refund->currency).' to you. '.$refund->note,
            'It appears in your payment history and on your statement of account.',
        ], 'View your payments', route('portal.payments.index')));
    }

    public function contractSentForSignature(Contract $c, ?string $note = null, bool $notifyAdmins = true): void
    {
        $this->client($c->client, new PortalMail('Contract '.$c->reference.' is ready to sign', array_values(array_filter([$note, 'The agreement "'.$c->title.'" ('.$c->amountLabel().') is ready for your signature.', 'Read it in your portal, then sign electronically in under a minute. '.self::workStarts($c)])), 'Read and sign the contract', route('portal.contracts.show', $c)));
        $notifyAdmins && ! $this->adminActed() && $this->admins(new PortalMail('Sent for signature: '.$c->reference, [$c->reference.' for '.$c->client->company.' was sent to the client to sign.'], 'Open contract', route('admin.contracts.show', $c)));
    }

    /** One email each: the client gets their signed copy and, when one was issued, the invoice to pay. */
    public function contractSigned(Contract $c, ?Invoice $invoice = null): void
    {
        $this->admins(new PortalMail('Signed: contract '.$c->reference, [
            $c->signed_name.' signed '.$c->reference.' for '.$c->client->company.' on '.$c->signed_at->format('j F Y \a\t g:ia').'.',
            $invoice ? 'The contract is now active. Invoice '.$invoice->reference.' ('.$invoice->title.', '.money($invoice->total, $invoice->currency).') was generated and emailed to the client.' : 'The contract is now active. It already had an invoice, so none was generated.',
        ], 'Open contract', route('admin.contracts.show', $c)));

        $lines = ['Thank you for signing "'.$c->title.'". Your signed copy, with the standard terms as agreed, is available in your portal at any time.'];
        if ($invoice) {
            $lines[] = 'Your invoice '.$invoice->reference.' is ready: '.$invoice->title.', '.money($invoice->total, $invoice->currency).($invoice->due_date ? ', due by '.$invoice->due_date->format('j F Y') : '').'.';
            $lines[] = $invoice->kind === 'deposit' ? 'Work usually starts once the deposit is received. Pay by bank transfer, cheque or cash, then submit the payment in your portal so we can confirm it.' : 'Pay by bank transfer, cheque or cash, then submit the payment in your portal so we can confirm it.';
        }
        $this->client($c->client, new PortalMail('Signed copy of contract '.$c->reference, $lines, $invoice ? 'View and pay the invoice' : 'Download the signed contract', $invoice ? route('portal.invoices.show', $invoice) : route('documents.contract', $c)));
    }

    public function contractStatus(Contract $c, string $action): void
    {
        $contact = 'Questions? Reply to this email or call '.config('company.phone').'.';
        [$subject, $lines] = match ($action) {
            'activate' => ['Contract '.$c->reference.' is now active', ['Contract '.$c->reference.' ("'.$c->title.'") is now active.']],
            'complete' => ['Contract '.$c->reference.' is complete', ['Contract '.$c->reference.' ("'.$c->title.'") has been marked completed. Thank you for working with us.']],
            'terminate' => ['Contract '.$c->reference.' has ended', array_values(array_filter(['Contract '.$c->reference.' ("'.$c->title.'") was terminated effective '.$c->terminated_at?->format('j F Y').'.', $c->termination_reason ? 'Reason: '.$c->termination_reason : null, $contact]))],
            'cancel' => ['Contract '.$c->reference.' was cancelled', ['Contract '.$c->reference.' ("'.$c->title.'") was cancelled before it started. Any unpaid invoices for it have been cancelled too.', $contact]],
            default => [null, []],
        };
        if ($subject) {
            $this->client($c->client, new PortalMail($subject, $lines, 'View contract', route('portal.contracts.show', $c)));
        }
    }

    /** The next-step sentence for a contract: a deposit is mentioned only where one is taken. */
    private static function workStarts(Contract $c): string
    {
        return ContractInvoicing::takesDeposit($c) ? 'Work starts once it is signed and the deposit is received.' : 'Work starts once it is signed.';
    }

    /** True when an admin is doing this themselves, so they need no email about it; automation and clients still notify. */
    private function adminActed(): bool
    {
        return (bool) request()->user()?->isAdmin();
    }

    /** Manual or scheduled nudge. Stamps reminded_at and writes to the audit log only when the email actually went out; null otherwise. */
    public function remind(Invoice|Quotation|Contract $doc, ?User $by = null): ?string
    {
        $mail = match (true) {
            $doc instanceof Invoice => $this->invoiceReminder($doc),
            $doc instanceof Quotation => $this->quotationReminder($doc),
            default => $this->contractReminder($doc),
        };
        if (! $this->client($doc->client, $mail)) {
            return null;
        }
        $doc->forceFill(['reminded_at' => now()])->save();
        Audit::log($doc, 'reminder_sent', ['by' => $by?->name ?? 'scheduler']);

        return $mail->subject;
    }

    private function invoiceReminder(Invoice $invoice): PortalMail
    {
        $balance = money($invoice->balance(), $invoice->currency);
        $due = match (true) {
            ! $invoice->due_date => '',
            Invoice::dateHasPassed($invoice->due_date) => ', which was due on '.$invoice->due_date->format('j F Y'),
            default => ', due on '.$invoice->due_date->format('j F Y'),
        };

        return new PortalMail('Reminder: invoice '.$invoice->reference.' has '.$balance.' outstanding', [
            'A friendly reminder that invoice '.$invoice->reference.' ("'.$invoice->title.'") has a balance of '.$balance.$due.'.',
            'Pay by bank transfer, cheque or cash and submit the payment in your portal so we can confirm it. If you have already paid and submitted it in the portal, you can ignore this reminder.',
        ], 'View and pay the invoice', route('portal.invoices.show', $invoice));
    }

    private function quotationReminder(Quotation $quotation): PortalMail
    {
        $validity = $quotation->valid_until ? ' and is valid until '.$quotation->valid_until->format('j F Y') : '';

        return new PortalMail('Reminder: quotation '.$quotation->reference.' is waiting for your answer', [
            'Quotation '.$quotation->reference.' ("'.$quotation->title.'", '.money($quotation->total, $quotation->currency).') is still open'.$validity.'.',
            'Accept or decline it in your portal, or reply if you would like any changes.',
        ], 'Review the quotation', route('portal.quotations.show', $quotation));
    }

    private function contractReminder(Contract $contract): PortalMail
    {
        return new PortalMail('Reminder: contract '.$contract->reference.' is waiting for your signature', [
            'The agreement "'.$contract->title.'" is ready to sign in your portal. '.self::workStarts($contract),
        ], 'Read and sign the contract', route('portal.contracts.show', $contract));
    }

    /**
     * A "Set your password" link for one portal user, sent only to that address (never to the client's other
     * logins). No password ever travels by email. Sent immediately rather than queued, so a delivery failure is
     * reported to the admin on the spot.
     */
    public function loginDetails(User $user, string $setPasswordUrl, bool $isNew = true): bool
    {
        $mail = $isNew
            ? new PortalMail('Your '.config('company.short').' client portal is ready', [
                'We have set up your '.config('company.name').' client portal. It is where you will review quotations, read and sign contracts, see invoices and tell us about payments.',
                'Your sign-in email is '.$user->email.'. Choose your password with the button below to get started.',
                'The link works for 7 days. After that, use "Forgot password?" on the sign-in page.',
            ], 'Set your password', $setPasswordUrl)
            : new PortalMail('Choose a new password for your '.config('company.short').' portal', [
                'Here is a link to choose a new password for your client portal ('.$user->email.').',
                'Your current password keeps working until you set a new one. The link works for 7 days.',
                'If you did not ask for this, you can ignore this email.',
            ], 'Choose a new password', $setPasswordUrl);

        return $this->deliver(function () use ($user, $mail) {
            $user->notifyNow($mail);

            return true;
        }, $mail);
    }

    /** True when the message was handed to the mailer; false when the client has nobody to email or sending failed. */
    private function client(Client $client, PortalMail $mail): bool
    {
        return $this->deliver(function () use ($client, $mail) {
            if (! $client->active) {
                Log::info('Email not sent: client is inactive.', ['client_id' => $client->id, 'subject' => $mail->subject]);
                $this->warn($client->company.' is marked inactive, so "'.$mail->subject.'" was not emailed. Reactivate the client to resume emails.');

                return false;
            }
            $users = $client->users;
            if ($users->isNotEmpty()) {
                Notification::send($users, $mail);

                return true;
            }
            if ($client->email) {
                Notification::route('mail', [$client->email => $client->contact_name])->notify($mail);

                return true;
            }
            Log::warning('Email not sent: client has no address or portal login.', ['client_id' => $client->id, 'subject' => $mail->subject]);
            $this->warn($client->company.' has no email address or portal login on file, so "'.$mail->subject.'" could not be sent. Add one on the client page.');

            return false;
        }, $mail);
    }

    private function admins(PortalMail $mail): bool
    {
        return $this->deliver(function () use ($mail) {
            Notification::send(User::where('role', 'admin')->get(), $mail);

            return true;
        }, $mail);
    }

    /**
     * Mail is sent synchronously (QUEUE_CONNECTION=sync), so an SMTP problem would otherwise surface as a 500
     * after the record was already saved. Instead the error is reported, the action stands, and an admin
     * sees a warning banner. With a real queue driver failures land in failed_jobs instead.
     */
    private function deliver(callable $send, PortalMail $mail): bool
    {
        try {
            return (bool) $send();
        } catch (\Throwable $e) {
            report($e);
            $this->warn('Saved, but the email "'.$mail->subject.'" could not be sent: '.$e->getMessage().' Check the MAIL settings.');

            return false;
        }
    }

    /** Delivery problems are shown to admins only; a client must never see SMTP internals or admin-side mail. */
    private function warn(string $message): void
    {
        if (request()->hasSession() && request()->user()?->isAdmin()) {
            session()->flash('warning', $message);
        }
    }
}
