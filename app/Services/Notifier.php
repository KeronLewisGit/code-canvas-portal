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
        $this->admins(new PortalMail('Sent: quotation '.$q->reference, [$q->reference.' for '.$q->client->company.' ('.money($q->total, $q->currency).') was emailed to the client.'], 'Open quotation', route('admin.quotations.show', $q)));
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
        $this->admins(new PortalMail('Sent: invoice '.$i->reference, [$i->reference.' for '.$i->client->company.' ('.money($i->total, $i->currency).') was emailed to the client.'], 'Open invoice', route('admin.invoices.show', $i)));
    }

    public function paymentSubmitted(Payment $p): void
    {
        $this->admins(new PortalMail('Payment to verify: '.money($p->amount, $p->currency).' from '.$p->client->company, [$p->client->company.' submitted a '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').($p->invoice ? ' against '.$p->invoice->reference : '').'.', $p->proof_path ? 'A proof file is attached in the portal.' : 'Reference: '.($p->reference ?: 'none given').'.', 'Please check your records and confirm or reject it.'], 'Review the payment', route('admin.payments.show', $p)));
        $this->client($p->client, new PortalMail('We received your payment submission', ['Thank you. Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' has been submitted and is awaiting verification.', 'We usually confirm within one working day and will email you once it is verified.'], 'View payment', route('portal.payments.show', $p)));
    }

    public function paymentDecided(Payment $p): void
    {
        $ok = $p->status === 'verified';
        $this->client($p->client, new PortalMail($ok ? 'Payment confirmed: '.money($p->amount, $p->currency) : 'We could not verify your payment', $ok
            ? ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' has been verified. Thank you.', $p->invoice ? 'Invoice '.$p->invoice->reference.' balance is now '.money($p->invoice->balance(), $p->invoice->currency).'.' : ($p->contract ? 'Contract '.$p->contract->reference.' balance is now '.money($p->contract->balance(), $p->contract->currency).'.' : 'Your balance has been updated.')]
            : ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' could not be verified.', 'Reason: '.$p->rejection_reason, 'Please check the details and submit it again, or reply to this email if you think this is a mistake.'],
            $ok ? 'View payment' : 'Submit again', $ok ? route('portal.payments.show', $p) : route('portal.payments.create', array_filter(['invoice' => $p->invoice_id, 'contract' => $p->contract_id]))));
    }

    public function contractSentForSignature(Contract $c, ?string $note = null, bool $notifyAdmins = true): void
    {
        $this->client($c->client, new PortalMail('Contract '.$c->reference.' is ready to sign', array_values(array_filter([$note, 'The agreement "'.$c->title.'" ('.$c->amountLabel().') is ready for your signature.', 'Read it in your portal, then sign electronically in under a minute. Work starts once it is signed and the deposit is received.'])), 'Read and sign the contract', route('portal.contracts.show', $c)));
        $notifyAdmins && $this->admins(new PortalMail('Sent for signature: '.$c->reference, [$c->reference.' for '.$c->client->company.' was sent to the client to sign.'], 'Open contract', route('admin.contracts.show', $c)));
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
        $text = match ($action) {
            'activate' => 'Contract '.$c->reference.' ("'.$c->title.'") is now active.',
            'complete' => 'Contract '.$c->reference.' ("'.$c->title.'") has been marked completed. Thank you for working with us.',
            'terminate' => 'Contract '.$c->reference.' ("'.$c->title.'") was terminated effective '.$c->terminated_at?->format('j F Y').'.',
            'cancel' => 'Contract '.$c->reference.' ("'.$c->title.'") was cancelled before it started.',
            default => null,
        };
        if ($text) {
            $this->client($c->client, new PortalMail('Update on contract '.$c->reference, [$text], 'View contract', route('portal.contracts.show', $c)));
        }
    }

    /** Manual or scheduled nudge. Stamps reminded_at and writes to the audit log only when the email actually went out; null otherwise. */
    public function remind(Invoice|Quotation|Contract $doc, ?User $by = null): ?string
    {
        [$subject, $lines, $label, $url] = match (true) {
            $doc instanceof Invoice => ['Reminder: invoice '.$doc->reference.' has '.money($doc->balance(), $doc->currency).' outstanding', ['A friendly reminder that invoice '.$doc->reference.' ("'.$doc->title.'") has a balance of '.money($doc->balance(), $doc->currency).($doc->due_date ? (Invoice::dateHasPassed($doc->due_date) ? ', which was due on ' : ', due on ').$doc->due_date->format('j F Y') : '').'.', 'Pay by bank transfer, cheque or cash and submit the payment in your portal so we can confirm it. If you have already paid, please submit the details and ignore this reminder.'], 'View and pay the invoice', route('portal.invoices.show', $doc)],
            $doc instanceof Quotation => ['Reminder: quotation '.$doc->reference.' is waiting for your answer', ['Quotation '.$doc->reference.' ("'.$doc->title.'", '.money($doc->total, $doc->currency).') is still open'.($doc->valid_until ? ' and is valid until '.$doc->valid_until->format('j F Y') : '').'.', 'Accept or decline it in your portal, or reply if you would like any changes.'], 'Review the quotation', route('portal.quotations.show', $doc)],
            default => ['Reminder: contract '.$doc->reference.' is waiting for your signature', ['The agreement "'.$doc->title.'" is ready to sign in your portal. Work starts once it is signed and the deposit is received.'], 'Read and sign the contract', route('portal.contracts.show', $doc)],
        };
        if (! $this->client($doc->client, new PortalMail($subject, $lines, $label, $url))) {
            return null;
        }
        $doc->forceFill(['reminded_at' => now()])->save();
        Audit::log($doc, 'reminder_sent', ['by' => $by?->name ?? 'scheduler']);

        return $subject;
    }

    /**
     * Login details for one portal user, sent only to that address (never to the client's other logins).
     * Sent immediately rather than queued, so the plain password is never written to the jobs table and a
     * delivery failure is reported to the admin on the spot.
     */
    public function loginDetails(User $user, string $password, bool $isNew = true): bool
    {
        $company = config('company.name');
        $mail = $isNew
            ? new PortalMail('Welcome to the '.config('company.short').' client portal', [
                'You have been onboarded as a client of '.$company.'. Your client portal is ready: it is where you will review quotations, read and sign contracts, see invoices and submit payments.',
                'Sign in with these details:',
                'Email: '.$user->email,
                'Password: '.$password,
                'The first time you sign in we will show you around, and you can choose your own password there. Please keep these details private.',
            ], 'Sign in to the portal', route('login'))
            : new PortalMail('Your '.config('company.short').' portal password was reset', [
                'A new password has been generated for your client portal login. The old one no longer works.',
                'Email: '.$user->email,
                'Password: '.$password,
                'Once signed in, choose your own password under Password in the menu.',
            ], 'Sign in to the portal', route('login'));

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
